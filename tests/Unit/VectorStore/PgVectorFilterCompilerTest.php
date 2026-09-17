<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Support\MetadataMatcher;
use Sellinnate\RagEngine\VectorStore\PgVectorFilterCompiler;

beforeEach(function () {
    $this->compiler = new PgVectorFilterCompiler;
});

it('compiles no filters to TRUE', function () {
    expect($this->compiler->compile([]))->toBe(['sql' => 'TRUE', 'bindings' => []]);
});

it('compiles scalar equality with bound key and jsonb value', function () {
    $compiled = $this->compiler->compile(['scope' => 'contracts', 'year' => 2024, 'ratio' => 1.0, 'active' => true]);

    expect($compiled['sql'])->toBe(
        '(COALESCE((metadata -> ?::text) = ?::jsonb, FALSE)) AND '
        .'(COALESCE((metadata -> ?::text) = ?::jsonb, FALSE)) AND '
        .'(COALESCE((metadata -> ?::text) = ?::jsonb, FALSE)) AND '
        .'(COALESCE((metadata -> ?::text) = ?::jsonb, FALSE))'
    )->and($compiled['bindings'])->toBe(['scope', '"contracts"', 'year', '2024', 'ratio', '1.0', 'active', 'true']);
});

it('keeps numeric strings distinct from numbers (strict equality)', function () {
    expect($this->compiler->compile(['tenant_id' => '1'])['bindings'])->toBe(['tenant_id', '"1"'])
        ->and($this->compiler->compile(['tenant_id' => 1])['bindings'])->toBe(['tenant_id', '1']);
});

it('compiles null as missing-or-json-null', function () {
    expect($this->compiler->compile(['tag' => null]))->toBe([
        'sql' => "((metadata -> ?::text) IS NULL OR COALESCE((metadata -> ?::text) = 'null'::jsonb, FALSE))",
        'bindings' => ['tag', 'tag'],
    ]);
});

it('compiles a list to a null-safe IN', function () {
    expect($this->compiler->compile(['scope' => ['internal', 'contracts']]))->toBe([
        'sql' => '(COALESCE((metadata -> ?::text) IN (?::jsonb, ?::jsonb), FALSE))',
        'bindings' => ['scope', '"internal"', '"contracts"'],
    ]);
});

it('adds the null branch when a list contains null, and FALSE for an empty list', function () {
    expect($this->compiler->compile(['tag' => ['x', null]]))->toBe([
        'sql' => "(COALESCE((metadata -> ?::text) IN (?::jsonb), FALSE) OR ((metadata -> ?::text) IS NULL OR COALESCE((metadata -> ?::text) = 'null'::jsonb, FALSE)))",
        'bindings' => ['tag', '"x"', 'tag', 'tag'],
    ])->and($this->compiler->compile(['tag' => []]))->toBe(['sql' => 'FALSE', 'bindings' => []])
        ->and($this->compiler->compile(['tag' => [null]])['sql'])->toBe("(((metadata -> ?::text) IS NULL OR COALESCE((metadata -> ?::text) = 'null'::jsonb, FALSE)))");
});

it('compiles eq / neq / in / nin operators', function () {
    expect($this->compiler->compile(['a' => ['eq' => 'x']])['sql'])->toBe('(COALESCE((metadata -> ?::text) = ?::jsonb, FALSE))')
        ->and($this->compiler->compile(['a' => ['neq' => 'x']])['sql'])->toBe('NOT (COALESCE((metadata -> ?::text) = ?::jsonb, FALSE))')
        ->and($this->compiler->compile(['a' => ['in' => ['k' => 'x']]]))->toBe([
            'sql' => '(COALESCE((metadata -> ?::text) IN (?::jsonb), FALSE))',
            'bindings' => ['a', '"x"'],
        ])
        ->and($this->compiler->compile(['a' => ['nin' => ['x']]])['sql'])->toBe('NOT (COALESCE((metadata -> ?::text) IN (?::jsonb), FALSE))')
        ->and($this->compiler->compile(['a' => ['in' => 'x']]))->toBe(['sql' => 'FALSE', 'bindings' => []])
        ->and($this->compiler->compile(['a' => ['nin' => 'x']]))->toBe(['sql' => 'FALSE', 'bindings' => []])
        ->and($this->compiler->compile(['a' => ['eq' => ['p', 'q']]])['bindings'])->toBe(['a', '["p","q"]']);
});

it('compiles typed numeric and string ranges', function () {
    expect($this->compiler->compile(['year' => ['gte' => 2024, 'lt' => 2026.5]]))->toBe([
        'sql' => "(CASE WHEN jsonb_typeof((metadata -> ?::text)) = 'number' THEN ((metadata -> ?::text))::numeric >= ?::numeric ELSE FALSE END)"
            ." AND (CASE WHEN jsonb_typeof((metadata -> ?::text)) = 'number' THEN ((metadata -> ?::text))::numeric < ?::numeric ELSE FALSE END)",
        'bindings' => ['year', 'year', 2024, 'year', 'year', '2026.5'],
    ])->and($this->compiler->compile(['date' => ['gt' => '2024-01-01']]))->toBe([
        'sql' => "(CASE WHEN jsonb_typeof((metadata -> ?::text)) = 'string' THEN (metadata ->> ?::text) COLLATE \"C\" > ? ELSE FALSE END)",
        'bindings' => ['date', 'date', '2024-01-01'],
    ])->and($this->compiler->compile(['n' => ['lte' => true]]))->toBe(['sql' => 'FALSE', 'bindings' => []])
        ->and($this->compiler->compile(['n' => ['lt' => null]]))->toBe(['sql' => 'FALSE', 'bindings' => []]);
});

it('rejects unsupported operators like the PHP matcher', function () {
    expect(fn () => $this->compiler->compile(['a' => ['like' => 'x%']]))->toThrow(RagException::class, 'Unsupported filter operator [like]')
        ->and(fn () => MetadataMatcher::matches(['a' => 'x'], ['a' => ['like' => 'x%']]))->toThrow(RagException::class, 'Unsupported filter operator [like]');
});

it('rejects keys that do not match the strict pattern', function (string $key) {
    $this->compiler->compile([$key => 'x']);
})->with([
    "scope') OR 1=1 --",
    'a b',
    'a"b',
    '',
    '-leading',
    str_repeat('k', 129),
    "tab\tkey",
])->throws(RagException::class, 'Invalid metadata filter key');

it('accepts dotted, dashed and numeric keys', function () {
    expect($this->compiler->compile(['a.b-c:d_1' => 1, '0' => 'x'])['bindings'])->toBe(['a.b-c:d_1', '1', '0', '"x"']);
});

it('rejects non-finite numbers and non-encodable values', function (array $filters) {
    $this->compiler->compile($filters);
})->with([
    [['n' => ['gt' => INF]]],
    [['n' => NAN]],
    [['n' => ['eq' => [NAN]]]],
    [['n' => ['in' => [new stdClass]]]],
])->throws(RagException::class);

it('resets bindings between compilations', function () {
    $this->compiler->compile(['a' => 'x']);

    expect($this->compiler->compile(['b' => 'y'])['bindings'])->toBe(['b', '"y"']);
});

it('matches range operators only between comparable types (PHP safety net)', function () {
    expect(MetadataMatcher::matches(['y' => 2024], ['y' => ['gte' => 2024]]))->toBeTrue()
        ->and(MetadataMatcher::matches(['y' => 2024.5], ['y' => ['lt' => 2025]]))->toBeTrue()
        ->and(MetadataMatcher::matches([], ['y' => ['lt' => 2025]]))->toBeFalse()
        ->and(MetadataMatcher::matches(['y' => '2024'], ['y' => ['gte' => 2000]]))->toBeFalse()
        ->and(MetadataMatcher::matches(['d' => '2024-02-01'], ['d' => ['gt' => '2024-01-31']]))->toBeTrue()
        ->and(MetadataMatcher::matches(['d' => '10'], ['d' => ['gt' => '9']]))->toBeFalse()
        ->and(MetadataMatcher::matches(['b' => true], ['b' => ['lte' => true]]))->toBeFalse();
});
