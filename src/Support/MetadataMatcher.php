<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Support;

use Sellinnate\RagEngine\Exceptions\RagException;

/**
 * Metadata filter matching shared by the in-process and SQL-backed vector
 * stores (FR-VS-08). The pgvector SQL push-down and the Qdrant translation
 * implement the same grammar:
 *
 * - `key => scalar` / `eq`: STRICT equality (`===`), so numeric-string tenant
 *   ids can never collide and leak across tenants. When the stored value is a
 *   list, it matches if the list CONTAINS the scalar.
 * - `key => null`: the key is missing, null, or an empty list.
 * - `key => [a, b]` / `in`: any of the values (for a stored list: any element).
 * - `neq` / `nin`: the negations of `eq` / `in` (a missing key matches).
 * - `gt`/`gte`/`lt`/`lte`: numbers with numbers, strings with strings
 *   (byte-wise, so ISO-8601 dates order correctly); for a stored list, any
 *   element may satisfy it. A missing value or a type mismatch never matches.
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
                    if (! self::in($actual, $expected)) {
                        return false;
                    }
                } elseif (! self::matchesOperators($actual, $expected)) {
                    return false;
                }
            } elseif (! self::equals($actual, $expected)) {
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
                'eq' => self::equals($actual, $value),
                'neq' => ! self::equals($actual, $value),
                'gt' => self::range($actual, $value, static fn (int $cmp): bool => $cmp > 0),
                'gte' => self::range($actual, $value, static fn (int $cmp): bool => $cmp >= 0),
                'lt' => self::range($actual, $value, static fn (int $cmp): bool => $cmp < 0),
                'lte' => self::range($actual, $value, static fn (int $cmp): bool => $cmp <= 0),
                'in' => is_array($value) && self::in($actual, $value),
                'nin' => is_array($value) && ! self::in($actual, $value),
                default => throw new RagException("Unsupported filter operator [{$op}]."),
            };

            if (! $result) {
                return false;
            }
        }

        return true;
    }

    private static function equals(mixed $actual, mixed $expected): bool
    {
        if ($expected === null) {
            return $actual === null || $actual === [];
        }

        if ($actual === $expected) {
            return true;
        }

        // A stored list contains the scalar.
        return ! is_array($expected) && is_array($actual) && array_is_list($actual) && in_array($expected, $actual, true);
    }

    /**
     * @param  array<mixed>  $candidates
     */
    private static function in(mixed $actual, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (self::equals($actual, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  callable(int): bool  $accept
     */
    private static function range(mixed $actual, mixed $expected, callable $accept): bool
    {
        $values = is_array($actual) && array_is_list($actual) ? $actual : [$actual];

        foreach ($values as $value) {
            $cmp = self::compare($value, $expected);

            if ($cmp !== null && $accept($cmp)) {
                return true;
            }
        }

        return false;
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
