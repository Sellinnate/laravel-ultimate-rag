<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Support;

use Sellinnate\RagEngine\Exceptions\RagException;

/**
 * Metadata filter matching shared by the in-process and SQL-backed vector
 * stores (FR-VS-08). Scalar equality is STRICT (=== ) so numeric-string tenant
 * ids can never collide and leak across tenants.
 *
 * Range operators (gt/gte/lt/lte) only compare like with like: numbers with
 * numbers, strings with strings (byte-wise, so ISO-8601 dates order
 * correctly). A missing value or a type mismatch never matches — the same
 * semantics the pgvector SQL push-down and Qdrant apply.
 */
final class MetadataMatcher
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $filters
     */
    public static function matches(array $metadata, array $filters): bool
    {
        foreach ($filters as $key => $expected) {
            $actual = $metadata[$key] ?? null;

            if (is_array($expected)) {
                if (array_is_list($expected)) {
                    if (! in_array($actual, $expected, true)) {
                        return false;
                    }
                } elseif (! self::matchesOperators($actual, $expected)) {
                    return false;
                }
            } elseif ($actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $operators
     */
    private static function matchesOperators(mixed $actual, array $operators): bool
    {
        foreach ($operators as $op => $value) {
            $result = match ($op) {
                'eq' => $actual === $value,
                'neq' => $actual !== $value,
                'gt' => ($cmp = self::compare($actual, $value)) !== null && $cmp > 0,
                'gte' => ($cmp = self::compare($actual, $value)) !== null && $cmp >= 0,
                'lt' => ($cmp = self::compare($actual, $value)) !== null && $cmp < 0,
                'lte' => ($cmp = self::compare($actual, $value)) !== null && $cmp <= 0,
                'in' => is_array($value) && in_array($actual, $value, true),
                'nin' => is_array($value) && ! in_array($actual, $value, true),
                default => throw new RagException("Unsupported filter operator [{$op}]."),
            };

            if (! $result) {
                return false;
            }
        }

        return true;
    }

    /**
     * Three-way comparison of two numbers or two strings; null when the
     * operands are not comparable.
     */
    private static function compare(mixed $actual, mixed $expected): ?int
    {
        if ((is_int($actual) || is_float($actual)) && (is_int($expected) || is_float($expected))) {
            return $actual <=> $expected;
        }

        if (is_string($actual) && is_string($expected)) {
            return strcmp($actual, $expected);
        }

        return null;
    }
}
