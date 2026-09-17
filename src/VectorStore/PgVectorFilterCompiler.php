<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\VectorStore;

use JsonException;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Support\MetadataMatcher;

/**
 * Compiles metadata filters into a parameterised Postgres `WHERE` fragment over
 * the `metadata` jsonb column, so the pgvector store filters BEFORE
 * `ORDER BY … LIMIT` (a selective filter still returns topK hits).
 *
 * Semantics mirror {@see MetadataMatcher} (which stays as a safety net),
 * including list-valued metadata (a stored list matches on its elements):
 *
 * - `key => scalar` / `eq`  strict equality, or list containment
 * - `key => null`           missing, JSON null or an empty list
 * - `key => [a, b]` / `in`  any value (any element of a stored list)
 * - `neq` / `nin`           negations
 * - `gt`/`gte`/`lt`/`lte`   numbers numerically, strings byte-wise; any
 *                           element of a stored list may satisfy them
 *
 * Every predicate is null-safe (never SQL NULL), so negations are plain `NOT`.
 *
 * Injection safety: keys AND values are bound parameters, keys are also
 * validated against {@see KEY_PATTERN}, and the SQL text itself is built only
 * from literals (typed `literal-string`).
 */
final class PgVectorFilterCompiler
{
    public const KEY_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.:\-]{0,127}$/';

    private const RANGE_OPERATORS = ['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];

    /** @var list<mixed> */
    private array $bindings = [];

    /**
     * @param  array<string, mixed>  $filters
     * @return array{sql: literal-string, bindings: list<mixed>}
     */
    public function compile(array $filters): array
    {
        $this->bindings = [];
        $clauses = [];

        foreach ($filters as $key => $expected) {
            $key = (string) $key;
            $this->assertKey($key);

            if (! is_array($expected)) {
                $clauses[] = $this->equals($key, $expected);

                continue;
            }

            if (array_is_list($expected)) {
                $clauses[] = $this->in($key, $expected);

                continue;
            }

            foreach ($expected as $operator => $value) {
                $clauses[] = $this->operator($key, (string) $operator, $value);
            }
        }

        return [
            'sql' => $clauses === [] ? 'TRUE' : implode(' AND ', $clauses),
            'bindings' => $this->bindings,
        ];
    }

    /**
     * @return literal-string
     */
    private function operator(string $key, string $operator, mixed $value): string
    {
        return match ($operator) {
            'eq' => $this->equals($key, $value),
            'neq' => 'NOT '.$this->equals($key, $value),
            'in' => is_array($value) ? $this->in($key, array_values($value)) : 'FALSE',
            'nin' => is_array($value) ? 'NOT '.$this->in($key, array_values($value)) : 'FALSE',
            'gt', 'gte', 'lt', 'lte' => $this->range($key, self::RANGE_OPERATORS[$operator], $value),
            default => throw new RagException("Unsupported filter operator [{$operator}]."),
        };
    }

    /**
     * Null-safe strict equality; a scalar also matches inside a stored list.
     *
     * @return literal-string
     */
    private function equals(string $key, mixed $value): string
    {
        if ($value === null) {
            return $this->isNull($key);
        }

        $field = $this->field($key);
        $this->bindJson($value);

        if (is_array($value)) {
            return "(COALESCE({$field} = ?::jsonb, FALSE))";
        }

        $container = $this->field($key);
        $this->bindJson([$value]);

        return "(COALESCE({$field} = ?::jsonb, FALSE) OR COALESCE({$container} @> ?::jsonb, FALSE))";
    }

    /**
     * Null-safe strict membership (element-wise for a stored list).
     *
     * @param  list<mixed>  $values
     * @return literal-string
     */
    private function in(string $key, array $values): string
    {
        $parts = [];
        $nonNull = array_values(array_filter($values, static fn (mixed $v): bool => $v !== null));

        if ($nonNull !== []) {
            $field = $this->field($key);
            $parts[] = 'COALESCE('.$field.' IN ('.$this->jsonPlaceholders($nonNull).'), FALSE)';

            $scalars = array_values(array_filter($nonNull, static fn (mixed $v): bool => ! is_array($v)));
            if ($scalars !== []) {
                $elements = $this->elements($key);
                $parts[] = 'EXISTS (SELECT 1 FROM '.$elements.' AS element(value) WHERE element.value IN ('.$this->jsonPlaceholders($scalars).'))';
            }
        }

        if (count($nonNull) !== count($values)) {
            $parts[] = $this->isNull($key);
        }

        return $parts === [] ? 'FALSE' : '('.implode(' OR ', $parts).')';
    }

    /**
     * Typed range comparison: numbers numerically, strings byte-wise; any
     * element of a stored list may satisfy it.
     *
     * @param  literal-string  $sqlOperator
     * @return literal-string
     */
    private function range(string $key, string $sqlOperator, mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            if (is_float($value) && ! is_finite($value)) {
                throw new RagException("Range filter on [{$key}] needs a finite number.");
            }

            $operand = is_int($value) ? $value : $this->floatString($value);

            $typeField = $this->field($key);
            $valueField = $this->field($key);
            $this->bindings[] = $operand;
            $elements = $this->elements($key);
            $this->bindings[] = $operand;

            return "(CASE WHEN jsonb_typeof({$typeField}) = 'number' THEN ({$valueField})::numeric {$sqlOperator} ?::numeric ELSE FALSE END"
                ." OR EXISTS (SELECT 1 FROM {$elements} AS element(value) WHERE CASE WHEN jsonb_typeof(element.value) = 'number' THEN (element.value)::numeric {$sqlOperator} ?::numeric ELSE FALSE END))";
        }

        if (is_string($value)) {
            $typeField = $this->field($key);
            $textField = $this->textField($key);
            $this->bindings[] = $value;
            $elements = $this->elements($key);
            $this->bindings[] = $value;

            return "(CASE WHEN jsonb_typeof({$typeField}) = 'string' THEN {$textField} COLLATE \"C\" {$sqlOperator} ? ELSE FALSE END"
                ." OR EXISTS (SELECT 1 FROM {$elements} AS element(value) WHERE CASE WHEN jsonb_typeof(element.value) = 'string' THEN (element.value #>> '{}') COLLATE \"C\" {$sqlOperator} ? ELSE FALSE END))";
        }

        // PHP never matches a range against null/bool/array operands either.
        return 'FALSE';
    }

    /**
     * Missing key, JSON null or an empty list.
     *
     * @return literal-string
     */
    private function isNull(string $key): string
    {
        $first = $this->field($key);
        $second = $this->field($key);

        return "({$first} IS NULL OR COALESCE({$second} IN ('null'::jsonb, '[]'::jsonb), FALSE))";
    }

    /**
     * The elements of a stored list (none when the value is not a list), as a
     * set-returning expression that never errors on scalars.
     *
     * @return literal-string
     */
    private function elements(string $key): string
    {
        $typeField = $this->field($key);
        $valueField = $this->field($key);

        return "jsonb_array_elements(CASE WHEN jsonb_typeof({$typeField}) = 'array' THEN {$valueField} ELSE '[]'::jsonb END)";
    }

    /**
     * `metadata -> ?` with the key bound as text (adds the binding).
     *
     * @return literal-string
     */
    private function field(string $key): string
    {
        $this->bindings[] = $key;

        return '(metadata -> ?::text)';
    }

    /**
     * @return literal-string
     */
    private function textField(string $key): string
    {
        $this->bindings[] = $key;

        return '(metadata ->> ?::text)';
    }

    /**
     * @param  list<mixed>  $values
     * @return literal-string
     */
    private function jsonPlaceholders(array $values): string
    {
        $placeholders = [];
        foreach ($values as $value) {
            $this->bindJson($value);
            $placeholders[] = '?::jsonb';
        }

        return implode(', ', $placeholders);
    }

    /**
     * Bind a value as a jsonb literal (scalars, or arrays compared as a whole).
     */
    private function bindJson(mixed $value): void
    {
        if (! is_scalar($value) && ! is_array($value)) {
            throw new RagException('Metadata filter values must be scalars or arrays.');
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new RagException('Metadata filter values must be finite numbers.');
        }

        try {
            $this->bindings[] = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new RagException('Metadata filter value cannot be encoded as JSON: '.$e->getMessage(), previous: $e);
        }
    }

    private function floatString(float $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function assertKey(string $key): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new RagException("Invalid metadata filter key [{$key}]: only [A-Za-z0-9_.:-] (max 128) is allowed.");
        }
    }
}
