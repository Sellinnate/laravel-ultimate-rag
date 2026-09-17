<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionResolverInterface;
use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Data\VectorRecord;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Managers\VectorStoreManager;
use Sellinnate\RagEngine\Pipeline\IngestionPipeline;
use Sellinnate\RagEngine\VectorStore\InMemoryVectorStore;
use Sellinnate\RagEngine\VectorStore\PgVectorStore;

/**
 * Native pgvector tests. The driver-resolution test runs everywhere; the
 * behavioural tests need a real Postgres with the `vector` extension and are
 * skipped unless RAG_PGVECTOR_TEST=1 (set in CI, which boots a pgvector service).
 */
function pgvectorConfigured(): bool
{
    return extension_loaded('pdo_pgsql') && getenv('RAG_PGVECTOR_TEST') === '1';
}

function pgStore(int $dimensions = 3, string $index = 'hnsw'): PgVectorStore
{
    configurePgConnection();

    return new PgVectorStore(
        app(ConnectionResolverInterface::class),
        connection: 'pgvector_test',
        table: 'rag_pgvectors_test',
        dimensions: $dimensions,
        metric: 'cosine',
        index: $index,
    );
}

function configurePgConnection(): void
{
    config()->set('database.connections.pgvector_test', [
        'driver' => 'pgsql',
        'host' => getenv('RAG_PGVECTOR_TEST_HOST') ?: '127.0.0.1',
        'port' => getenv('RAG_PGVECTOR_TEST_PORT') ?: '5432',
        'database' => getenv('RAG_PGVECTOR_TEST_DB') ?: 'rag',
        'username' => getenv('RAG_PGVECTOR_TEST_USER') ?: 'postgres',
        'password' => getenv('RAG_PGVECTOR_TEST_PASSWORD') ?: 'postgres',
        'charset' => 'utf8',
        'prefix' => '',
        'search_path' => 'public',
        'sslmode' => 'prefer',
    ]);
}

it('resolves the pgvector driver to PgVectorStore (no DB needed)', function () {
    config()->set('rag-engine.defaults.vector_store', 'pgvector');

    expect(app(VectorStoreManager::class)->driver())->toBeInstanceOf(PgVectorStore::class);
});

it('rejects a dimension mismatch with a clear error', function () {
    // No DB connection is touched before the dimension check.
    pgStore()->createNamespace('docs', 8, 'cosine');
})->throws(RagException::class, 'dimensions');

describe('native pgvector (requires Postgres + vector extension)', function () {
    beforeEach(function () {
        if (! pgvectorConfigured()) {
            $this->markTestSkipped('Set RAG_PGVECTOR_TEST=1 with a reachable pgvector Postgres to run these.');
        }

        $this->store = pgStore();
        // Clean slate.
        app(ConnectionResolverInterface::class)->connection('pgvector_test')
            ->statement('DROP TABLE IF EXISTS rag_pgvectors_test');
        $this->store->createNamespace('docs', 3, 'cosine');
    });

    afterEach(function () {
        if (pgvectorConfigured()) {
            app(ConnectionResolverInterface::class)->connection('pgvector_test')
                ->statement('DROP TABLE IF EXISTS rag_pgvectors_test');
        }
    });

    it('upserts and ranks by cosine similarity using the native index', function () {
        $this->store->upsert('docs', [
            new VectorRecord('near', [1.0, 0.0, 0.0], ['tenant_id' => 't1', 'content' => 'near']),
            new VectorRecord('far', [0.0, 0.0, 1.0], ['tenant_id' => 't1', 'content' => 'far']),
        ]);

        $hits = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', topK: 10, tenantId: 't1'));

        expect($hits[0]->id)->toBe('near')
            ->and($hits[0]->score)->toBeGreaterThan($hits[1]->score)
            ->and($hits[0]->vector)->toBe([1.0, 0.0, 0.0])
            ->and($this->store->count('docs'))->toBe(2)
            ->and($this->store->name())->toBe('pgvector');
    });

    it('scopes results to the tenant', function () {
        $this->store->upsert('docs', [
            new VectorRecord('t1doc', [1.0, 0.0, 0.0], ['tenant_id' => 't1', 'content' => 'a']),
            new VectorRecord('t2doc', [1.0, 0.0, 0.0], ['tenant_id' => 't2', 'content' => 'b']),
        ]);

        $hits = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', topK: 10, tenantId: 't1'));

        expect($hits)->toHaveCount(1)->and($hits[0]->id)->toBe('t1doc');
    });

    it('applies metadata filters and deletes by filter', function () {
        $this->store->upsert('docs', [
            new VectorRecord('a', [1.0, 0.0, 0.0], ['tenant_id' => 't1', 'tag' => 'keep']),
            new VectorRecord('b', [0.9, 0.1, 0.0], ['tenant_id' => 't1', 'tag' => 'drop']),
        ]);

        $kept = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', topK: 10, filters: ['tag' => 'keep'], tenantId: 't1'));
        expect($kept)->toHaveCount(1)->and($kept[0]->id)->toBe('a');

        $this->store->deleteByFilter('docs', ['tenant_id' => 't1']);
        expect($this->store->count('docs'))->toBe(0);
    });

    it('returns topK hits for a selective filter (filters run before LIMIT)', function () {
        // 200 near vectors in the wrong scope, 5 far ones in the right scope.
        $records = [];
        for ($i = 0; $i < 200; $i++) {
            $records[] = new VectorRecord("noise-{$i}", [1.0, 0.001 * $i, 0.0], ['tenant_id' => 't1', 'scope' => 'internal']);
        }
        for ($i = 0; $i < 5; $i++) {
            $records[] = new VectorRecord("contract-{$i}", [0.0, 0.1 * $i, 1.0], ['tenant_id' => 't1', 'scope' => 'contracts']);
        }
        $this->store->upsert('docs', $records);

        $hits = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', topK: 5, filters: ['scope' => ['in' => ['contracts', 'board']]], tenantId: 't1'));

        expect($hits)->toHaveCount(5);
        foreach ($hits as $hit) {
            expect($hit->metadata['scope'])->toBe('contracts');
        }
    });

    it('returns topK filtered hits through the HNSW index', function () {
        $records = [];
        for ($i = 0; $i < 300; $i++) {
            $records[] = new VectorRecord("n-{$i}", [1.0, sin($i), cos($i)], ['tenant_id' => 't1', 'scope' => $i % 50 === 0 ? 'rare' : 'common']);
        }
        $this->store->upsert('docs', $records);

        $conn = app(ConnectionResolverInterface::class)->connection('pgvector_test');
        $conn->statement('ANALYZE rag_pgvectors_test');
        $conn->statement('SET enable_seqscan = off');

        try {
            $hits = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', topK: 6, filters: ['scope' => 'rare'], tenantId: 't1'));
        } finally {
            $conn->statement('SET enable_seqscan = on');
        }

        expect($hits)->toHaveCount(6);
    });

    it('pushes every operator down with the same semantics as the PHP matcher', function () {
        $records = [
            new VectorRecord('a', [1.0, 0.0, 0.0], ['tenant_id' => 't1', 'year' => 2023, 'score' => 1.5, 'tag' => 'x', 'date' => '2024-01-10', 'flag' => true, 'tags' => ['p', 'q'], 'years' => [2019, 2020]]),
            new VectorRecord('b', [1.0, 0.1, 0.0], ['tenant_id' => 't1', 'year' => 2024, 'score' => 2.5, 'tag' => 'y', 'date' => '2024-03-01', 'flag' => false, 'tags' => ['q', 'hr'], 'years' => [2025]]),
            new VectorRecord('c', [1.0, 0.2, 0.0], ['tenant_id' => 't1', 'year' => '2025', 'tag' => null, 'num' => '1', 'tags' => []]),
            new VectorRecord('d', [1.0, 0.3, 0.0], ['tenant_id' => 't1', 'num' => 1, 'tags' => ['1'], 'dates' => ['2026-05-01']]),
        ];
        $this->store->upsert('docs', $records);

        $memory = new InMemoryVectorStore;
        $memory->createNamespace('docs', 3);
        $memory->upsert('docs', $records);

        $ids = static function ($store, array $filters): array {
            $hits = $store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', topK: 10, filters: $filters, tenantId: 't1'));
            $ids = array_map(static fn ($h) => $h->id, $hits);
            sort($ids);

            return $ids;
        };

        $cases = [
            [['tag' => 'x'], ['a']],
            [['tag' => null], ['c', 'd']],
            [['tag' => ['x', null]], ['a', 'c', 'd']],
            [['tag' => []], []],
            [['flag' => true], ['a']],
            [['flag' => false], ['b']],
            [['num' => 1], ['d']],
            [['num' => '1'], ['c']],
            [['tags' => ['eq' => ['p', 'q']]], ['a']],
            [['year' => ['gte' => 2024]], ['b']],
            [['year' => ['gt' => 2022, 'lt' => 2024]], ['a']],
            [['score' => ['lte' => 2.0]], ['a']],
            [['date' => ['gte' => '2024-02-01']], ['b']],
            [['year' => ['lt' => '2030']], ['c']],
            [['year' => ['gt' => true]], []],
            [['tag' => ['eq' => 'y']], ['b']],
            [['tag' => ['neq' => 'x']], ['b', 'c', 'd']],
            [['tag' => ['neq' => null]], ['a', 'b']],
            [['tag' => ['in' => ['x', 'y']]], ['a', 'b']],
            [['tag' => ['nin' => ['x']]], ['b', 'c', 'd']],
            [['tag' => ['nin' => ['x', null]]], ['b']],
            // List-valued metadata matches on its elements.
            [['tags' => 'q'], ['a', 'b']],
            [['tags' => 'hr'], ['b']],
            [['tags' => ['hr', 'p']], ['a', 'b']],
            [['tags' => ['in' => ['legal']]], []],
            [['tags' => ['nin' => ['hr']]], ['a', 'c', 'd']],
            [['tags' => ['neq' => 'q']], ['c', 'd']],
            [['tags' => null], ['c']],
            [['tags' => ['neq' => null]], ['a', 'b', 'd']],
            [['tags' => 1], []],
            [['years' => ['gte' => 2025]], ['b']],
            [['years' => ['lt' => 2020]], ['a']],
            [['dates' => ['gt' => '2026-01-01']], ['d']],
        ];

        foreach ($cases as [$filters, $expected]) {
            $label = json_encode($filters);
            expect($ids($this->store, $filters))->toBe($expected, "pgvector {$label}")
                ->and($ids($memory, $filters))->toBe($expected, "memory {$label}");
        }
    });

    it('restores the caller\'s index-scan settings when searching inside a transaction', function () {
        $this->store->upsert('docs', [new VectorRecord('a', [1.0, 0.0, 0.0], ['tenant_id' => 't1', 'scope' => 'x'])]);
        $conn = app(ConnectionResolverInterface::class)->connection('pgvector_test');

        $conn->transaction(function () use ($conn) {
            $conn->statement('SET LOCAL hnsw.ef_search = 77');
            $conn->statement('SET LOCAL enable_indexscan = on');

            $hits = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', topK: 50, filters: ['scope' => 'x'], tenantId: 't1'));

            expect($hits)->toHaveCount(1)
                ->and($conn->selectOne("SELECT current_setting('hnsw.ef_search') AS v")->v)->toBe('77')
                ->and($conn->selectOne("SELECT current_setting('enable_indexscan') AS v")->v)->toBe('on');
        });
    });

    it('treats hostile filter keys and values as data, never SQL', function () {
        $this->store->upsert('docs', [new VectorRecord('a', [1.0, 0.0, 0.0], ['tenant_id' => 't1', 'tag' => "x'; DROP TABLE rag_pgvectors_test; --"])]);

        $hits = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', topK: 10, filters: ['tag' => "x'; DROP TABLE rag_pgvectors_test; --"], tenantId: 't1'));
        expect($hits)->toHaveCount(1);

        expect(fn () => $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', topK: 10, filters: ["tag') OR 1=1 --" => 'x'], tenantId: 't1')))
            ->toThrow(RagException::class, 'Invalid metadata filter key');

        expect($this->store->count('docs'))->toBe(1);
    });

    it('deletes by a metadata filter pushed into SQL', function () {
        $this->store->upsert('docs', [
            new VectorRecord('a', [1.0, 0.0, 0.0], ['tenant_id' => 't1', 'document_id' => 'd1']),
            new VectorRecord('b', [1.0, 0.1, 0.0], ['tenant_id' => 't1', 'document_id' => 'd2']),
        ]);

        $this->store->deleteByFilter('docs', ['document_id' => 'd1']);

        $left = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', topK: 10, tenantId: 't1'));
        expect($left)->toHaveCount(1)->and($left[0]->id)->toBe('b');
    });

    it('works with an ivfflat index too', function () {
        app(ConnectionResolverInterface::class)->connection('pgvector_test')->statement('DROP TABLE IF EXISTS rag_pgvectors_test');
        $store = pgStore(index: 'ivfflat');
        $store->createNamespace('docs', 3, 'cosine');
        $store->upsert('docs', [
            new VectorRecord('a', [1.0, 0.0, 0.0], ['tenant_id' => 't1', 'scope' => 'x']),
            new VectorRecord('b', [0.0, 1.0, 0.0], ['tenant_id' => 't1', 'scope' => 'y']),
        ]);

        $hits = $store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', topK: 5, filters: ['scope' => 'y'], tenantId: 't1'));

        expect($hits)->toHaveCount(1)->and($hits[0]->id)->toBe('b');
    });

    it('runs the full pipeline: scoped Eloquent-style metadata, no plaintext, hydrated hits', function () {
        config()->set('rag-engine.defaults.vector_store', 'pgvector');
        config()->set('rag-engine.vector_stores.pgvector', [
            'driver' => 'pgvector',
            'connection' => 'pgvector_test',
            'table' => 'rag_pgvectors_test',
            'dimensions' => 8,
            'index' => 'hnsw',
        ]);
        app(ConnectionResolverInterface::class)->connection('pgvector_test')->statement('DROP TABLE IF EXISTS rag_pgvectors_test');
        app(VectorStoreManager::class)->forgetDrivers();

        foreach (['internal' => 'Internal holiday calendar.', 'contracts' => 'Supplier contract renewal terms.'] as $scope => $text) {
            $document = Rag::ingest(Rag::source()->text($text, ['rag_vector_metadata' => ['scope' => $scope]]));
            app(IngestionPipeline::class)->process($document);
        }

        $rows = app(ConnectionResolverInterface::class)->connection('pgvector_test')->select('SELECT metadata, content FROM rag_pgvectors_test');
        expect($rows)->toHaveCount(2);
        foreach ($rows as $row) {
            expect($row->content)->toBe('')
                ->and((string) $row->metadata)->not->toContain('contract renewal');
        }

        $hits = Rag::search('contract renewal')->where('scope', ['in' => ['contracts']])->topK(5)->get();

        expect($hits)->toHaveCount(1)
            ->and($hits[0]->content)->toContain('Supplier contract renewal')
            ->and($hits[0]->metadata['scope'])->toBe('contracts');
    });

    it('upsert is idempotent on the id (ON CONFLICT)', function () {
        $this->store->upsert('docs', [new VectorRecord('x', [1.0, 0.0, 0.0], ['tenant_id' => 't1', 'content' => 'v1'])]);
        $this->store->upsert('docs', [new VectorRecord('x', [0.0, 1.0, 0.0], ['tenant_id' => 't1', 'content' => 'v2'])]);

        expect($this->store->count('docs'))->toBe(1);
        $hits = $this->store->search('docs', [0.0, 1.0, 0.0], new RetrievalQuery('q', topK: 1, tenantId: 't1'));
        expect($hits[0]->content)->toBe('v2');
    });
});
