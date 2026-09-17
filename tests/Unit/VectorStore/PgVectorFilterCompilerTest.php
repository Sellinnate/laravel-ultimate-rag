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

const PG_EQ = '(COALESCE((metadata -> ?::text) = ?::jsonb, FALSE) OR COALESCE((metadata -> ?::text) @> ?::jsonb, FALSE))';
const PG_NULL = "((metadata -> ?::text) IS NULL OR COALESCE((metadata -> ?::text) IN ('null'::jsonb, '[]'::jsonb), FALSE))";
const PG_ELEMENTS = "jsonb_array_elements(CASE WHEN jsonb_typeof((metadata -> ?::text)) = 'array' THEN (metadata -> ?::text) ELSE '[]'::jsonb END)";

it('compiles scalar equality (or list containment) with bound keys and jsonb values', function () {
    $compiled = $this->compiler->compile(['scope' => 'contracts', 'year' => 2024, 'ratio' => 1.0, 'active' => true]);

    expect($compiled['sql'])->toBe(implode(' AND ', array_fill(0, 4, PG_EQ)))
        ->and($compiled['bindings'])->toBe([
            'scope', '"contracts"', 'scope', '["contracts"]',
            'year', '2024', 'year', '[2024]',
            'ratio', '1.0', 'ratio', '[1.0]',
            'active', 'true', 'active', '[true]',
        ]);
});

it('keeps numeric strings distinct from numbers (strict equality)', function () {
    expect($this->compiler->compile(['tenant_id' => '1'])['bindings'])->toBe(['tenant_id', '"1"', 'tenant_id', '["1"]'])
        ->and($this->compiler->compile(['tenant_id' => 1])['bindings'])->toBe(['tenant_id', '1', 'tenant_id', '[1]']);
});

it('compiles null as missing, json null or an empty list', function () {
    expect($this->compiler->compile(['tag' => null]))->toBe(['sql' => PG_NULL, 'bindings' => ['tag', 'tag']]);
});

it('compiles a list to a null-safe, element-aware IN', function () {
    expect($this->compiler->compile(['scope' => ['internal', 'contracts']]))->toBe([
        'sql' => '(COALESCE((metadata -> ?::text) IN (?::jsonb, ?::jsonb), FALSE) OR EXISTS (SELECT 1 FROM '.PG_ELEMENTS.' AS element(value) WHERE element.value IN (?::jsonb, ?::jsonb)))',
        'bindings' => ['scope', '"internal"', '"contracts"', 'scope', 'scope', '"internal"', '"contracts"'],
    ]);
});

it('adds the null branch when a list contains null, and FALSE for an empty list', function () {
    expect($this->compiler->compile(['tag' => ['x', null]]))->toBe([
        'sql' => '(COALESCE((metadata -> ?::text) IN (?::jsonb), FALSE) OR EXISTS (SELECT 1 FROM '.PG_ELEMENTS.' AS element(value) WHERE element.value IN (?::jsonb)) OR '.PG_NULL.')',
        'bindings' => ['tag', '"x"', 'tag', 'tag', '"x"', 'tag', 'tag'],
    ])->and($this->compiler->compile(['tag' => []]))->toBe(['sql' => 'FALSE', 'bindings' => []])
        ->and($this->compiler->compile(['tag' => [null]])['sql'])->toBe('('.PG_NULL.')')
        ->and($this->compiler->compile(['tag' => [['p', 'q']]]))->toBe([
            'sql' => '(COALESCE((metadata -> ?::text) IN (?::jsonb), FALSE))',
            'bindings' => ['tag', '["p","q"]'],
        ]);
});

it('compiles eq / neq / in / nin operators', function () {
    expect($this->compiler->compile(['a' => ['eq' => 'x']])['sql'])->toBe(PG_EQ)
        ->and($this->compiler->compile(['a' => ['neq' => 'x']])['sql'])->toBe('NOT '.PG_EQ)
        ->and($this->compiler->compile(['a' => ['neq' => null]])['sql'])->toBe('NOT '.PG_NULL)
        ->and($this->compiler->compile(['a' => ['in' => ['k' => 'x']]])['bindings'])->toBe(['a', '"x"', 'a', 'a', '"x"'])
        ->and($this->compiler->compile(['a' => ['nin' => ['x']]])['sql'])->toStartWith('NOT (COALESCE((metadata -> ?::text) IN (?::jsonb), FALSE) OR EXISTS')
        ->and($this->compiler->compile(['a' => ['in' => 'x']]))->toBe(['sql' => 'FALSE', 'bindings' => []])
        ->and($this->compiler->compile(['a' => ['nin' => 'x']]))->toBe(['sql' => 'FALSE', 'bindings' => []])
        ->and($this->compiler->compile(['a' => ['eq' => ['p', 'q']]]))->toBe([
            'sql' => '(COALESCE((metadata -> ?::text) = ?::jsonb, FALSE))',
            'bindings' => ['a', '["p","q"]'],
        ]);
});

it('compiles typed numeric and string ranges, element-wise for lists', function () {
    $numeric = "(CASE WHEN jsonb_typeof((metadata -> ?::text)) = 'number' THEN ((metadata -> ?::text))::numeric >= ?::numeric ELSE FALSE END"
        .' OR EXISTS (SELECT 1 FROM '.PG_ELEMENTS." AS element(value) WHERE CASE WHEN jsonb_typeof(element.value) = 'number' THEN (element.value)::numeric >= ?::numeric ELSE FALSE END))";
    $string = "(CASE WHEN jsonb_typeof((metadata -> ?::text)) = 'string' THEN (metadata ->> ?::text) COLLATE \"C\" > ? ELSE FALSE END"
        .' OR EXISTS (SELECT 1 FROM '.PG_ELEMENTS." AS element(value) WHERE CASE WHEN jsonb_typeof(element.value) = 'string' THEN (element.value #>> '{}') COLLATE \"C\" > ? ELSE FALSE END))";

    expect($this->compiler->compile(['year' => ['gte' => 2024]]))->toBe([
        'sql' => $numeric,
        'bindings' => ['year', 'year', 2024, 'year', 'year', 2024],
    ])->and($this->compiler->compile(['score' => ['lt' => 2026.5]])['bindings'])->toBe(['score', 'score', '2026.5', 'score', 'score', '2026.5'])
        ->and($this->compiler->compile(['date' => ['gt' => '2024-01-01']]))->toBe([
            'sql' => $string,
            'bindings' => ['date', 'date', '2024-01-01', 'date', 'date', '2024-01-01'],
        ])
        ->and($this->compiler->compile(['n' => ['lte' => true]]))->toBe(['sql' => 'FALSE', 'bindings' => []])
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
    expect($this->compiler->compile(['a.b-c:d_1' => 1, '0' => 'x'])['bindings'])->toBe(['a.b-c:d_1', '1', 'a.b-c:d_1', '[1]', '0', '"x"', '0', '["x"]']);
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

    expect($this->compiler->compile(['b' => ['y', 'z']])['bindings'])->toBe(['b', '"y"', '"z"', 'b', 'b', '"y"', '"z"']);
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

it('matches list-valued metadata on its elements (PHP safety net)', function () {
    $doc = ['tags' => ['hr', 'finance'], 'years' => [2023, 2026], 'empty' => []];

    expect(MetadataMatcher::matches($doc, ['tags' => 'hr']))->toBeTrue()
        ->and(MetadataMatcher::matches($doc, ['tags' => 'legal']))->toBeFalse()
        ->and(MetadataMatcher::matches($doc, ['tags' => ['legal', 'finance']]))->toBeTrue()
        ->and(MetadataMatcher::matches($doc, ['tags' => ['in' => ['legal']]]))->toBeFalse()
        ->and(MetadataMatcher::matches($doc, ['tags' => ['nin' => ['hr']]]))->toBeFalse()
        ->and(MetadataMatcher::matches($doc, ['tags' => ['nin' => ['legal']]]))->toBeTrue()
        ->and(MetadataMatcher::matches($doc, ['tags' => ['neq' => 'finance']]))->toBeFalse()
        ->and(MetadataMatcher::matches($doc, ['tags' => ['eq' => ['hr', 'finance']]]))->toBeTrue()
        ->and(MetadataMatcher::matches($doc, ['years' => ['gte' => 2025]]))->toBeTrue()
        ->and(MetadataMatcher::matches($doc, ['years' => ['lt' => 2020]]))->toBeFalse()
        ->and(MetadataMatcher::matches($doc, ['empty' => null]))->toBeTrue()
        ->and(MetadataMatcher::matches($doc, ['tags' => null]))->toBeFalse()
        ->and(MetadataMatcher::matches($doc, ['tags' => ['1']]))->toBeFalse()
        ->and(MetadataMatcher::matches(['n' => ['1']], ['n' => 1]))->toBeFalse();
});
