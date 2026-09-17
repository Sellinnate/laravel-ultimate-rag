<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\VectorStore;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Sellinnate\RagEngine\Contracts\VectorStore;
use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Support\MetadataMatcher;

/**
 * Native pgvector store (FR-VS-02) — real ANN search inside Postgres.
 *
 * Vectors are stored in a `vector(D)` column and queried with pgvector's distance
 * operators (`<=>` cosine, `<->` L2, `<#>` inner product), so scoring and top-k
 * selection run in the database against an HNSW index — not in PHP. The schema
 * (extension + table + index) is created lazily on first use.
 *
 * Metadata filters are compiled to SQL on the `metadata` jsonb column
 * ({@see PgVectorFilterCompiler}) and applied BEFORE `ORDER BY … LIMIT`, so a
 * selective filter (e.g. an access `scope`) still returns topK hits. The HNSW
 * candidate list (`hnsw.ef_search`) is raised to cover the requested limit; a
 * filtered query uses iterative index scans on pgvector >= 0.8 and an exact
 * scan on older versions (see {@see tuneIndexScan()}).
 * The PHP {@see MetadataMatcher} still runs on every row as a safety net.
 *
 * Single fixed embedding dimension per store (one embedding model per
 * deployment): mixed dimensions need separate stores, the portable `database`
 * driver, or Qdrant.
 *
 * Coverage note: this driver needs a real Postgres with the `vector` extension,
 * so it cannot run in the default SQLite test/coverage run. It is fully
 * integration-tested in CI (the dedicated "pgvector" job boots a pgvector
 * service); hence it is excluded from the line-coverage metric here.
 *
 * @codeCoverageIgnore
 */
final class PgVectorStore implements VectorStore
{
    /** metric => [operator, index opclass] */
    private const METRICS = [
        'cosine' => ['<=>', 'vector_cosine_ops'],
        'l2' => ['<->', 'vector_l2_ops'],
        'dot' => ['<#>', 'vector_ip_ops'],
    ];

    /** pgvector caps hnsw.ef_search at 1000. */
    private const MAX_EF_SEARCH = 1000;

    private bool $schemaReady = false;

    private ?string $extensionVersion = null;

    private readonly PgVectorFilterCompiler $filters;

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly ?string $connection = null,
        private readonly string $table = 'rag_pgvectors',
        private readonly int $dimensions = 1536,
        private readonly string $metric = 'cosine',
        private readonly string $index = 'hnsw',
    ) {
        $this->filters = new PgVectorFilterCompiler;
    }

    public function createNamespace(string $namespace, int $dimensions, string $metric = 'cosine'): void
    {
        if (! isset(self::METRICS[$metric])) {
            throw new RagException("Unsupported distance metric [{$metric}].");
        }

        if ($metric !== $this->metric) {
            throw new RagException(
                "pgvector store is configured for the [{$this->metric}] metric; namespace [{$namespace}] requested [{$metric}]."
            );
        }

        if ($dimensions !== $this->dimensions) {
            throw new RagException(
                "pgvector store is fixed to {$this->dimensions} dimensions; namespace [{$namespace}] needs {$dimensions}. "
                .'Set rag-engine.vector_stores.pgvector.dimensions to match your embedding model, or use the [database] / [qdrant] driver for mixed dimensions.'
            );
        }

        $this->ensureSchema();
    }

    public function namespaceExists(string $namespace): bool
    {
        return $this->tableExists();
    }

    public function deleteNamespace(string $namespace): void
    {
        if ($this->tableExists()) {
            $this->conn()->table($this->table)->where('namespace', $namespace)->delete();
        }
    }

    public function upsert(string $namespace, array $records): void
    {
        if ($records === []) {
            return;
        }

        $this->ensureSchema();

        $sql = "INSERT INTO {$this->table} (id, namespace, tenant_id, embedding, metadata, content) "
            .'VALUES (?, ?, ?, ?::vector, ?::jsonb, ?) '
            .'ON CONFLICT (id) DO UPDATE SET namespace = EXCLUDED.namespace, tenant_id = EXCLUDED.tenant_id, '
            .'embedding = EXCLUDED.embedding, metadata = EXCLUDED.metadata, content = EXCLUDED.content';

        $conn = $this->conn();
        $conn->transaction(function () use ($conn, $sql, $namespace, $records): void {
            foreach ($records as $record) {
                if ($record->dimensions() !== $this->dimensions) {
                    throw new RagException(
                        "Vector for [{$record->id}] has {$record->dimensions()} dimensions, expected {$this->dimensions}."
                    );
                }

                $conn->statement($sql, [
                    $record->id,
                    $namespace,
                    $record->tenantId(),
                    $this->toVector($record->vector),
                    json_encode($record->metadata, JSON_THROW_ON_ERROR),
                    is_string($record->metadata['content'] ?? null) ? $record->metadata['content'] : '',
                ]);
            }
        });
    }

    public function search(string $namespace, array $vector, RetrievalQuery $query): array
    {
        if (! $this->tableExists() || count($vector) !== $this->dimensions) {
            return [];
        }

        [$operator] = self::METRICS[$this->metric];
        $vectorParam = $this->toVector($vector);

        // Headroom for the score threshold and the PHP safety net.
        $limit = max($query->topK * 5, $query->topK);

        $sql = "SELECT id, metadata, content, embedding::text AS emb, (embedding {$operator} ?::vector) AS distance "
            ."FROM {$this->table} WHERE namespace = ?";
        $bindings = [$vectorParam, $namespace];

        if ($query->tenantId !== null) {
            $sql .= ' AND tenant_id = ?';
            $bindings[] = $query->tenantId;
        }

        // Metadata filters run in SQL, before ORDER BY/LIMIT (no under-fill).
        if ($query->filters !== []) {
            $compiled = $this->filters->compile($query->filters);
            $sql .= ' AND ('.$compiled['sql'].')';
            array_push($bindings, ...$compiled['bindings']);
        }

        $sql .= " ORDER BY embedding {$operator} ?::vector LIMIT ?";
        $bindings[] = $vectorParam;
        $bindings[] = $limit;

        $filters = $query->filters;
        if ($query->tenantId !== null) {
            $filters['tenant_id'] = $query->tenantId;
        }

        $conn = $this->conn();
        $rows = $conn->transaction(function () use ($conn, $sql, $bindings, $limit, $query): array {
            $this->tuneIndexScan($conn, $limit, $query->filters !== []);

            return $conn->select($sql, $bindings);
        });

        $hits = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $metadata */
            $metadata = json_decode((string) $row->metadata, true) ?: [];

            if (! MetadataMatcher::matches($metadata, $filters)) {
                continue;
            }

            $score = $this->scoreFromDistance((float) $row->distance);

            if ($query->scoreThreshold !== null && $score < $query->scoreThreshold) {
                continue;
            }

            $hits[] = new SearchHit(
                id: (string) $row->id,
                score: $score,
                content: (string) ($metadata['content'] ?? ''),
                metadata: $metadata,
                documentId: isset($metadata['document_id']) ? (string) $metadata['document_id'] : null,
                chunkId: isset($metadata['chunk_id']) ? (string) $metadata['chunk_id'] : null,
                vector: $this->parseVector((string) $row->emb),
            );
        }

        // Relaxed-order iterative scans may return near-sorted rows.
        usort($hits, static fn (SearchHit $a, SearchHit $b): int => $b->score <=> $a->score);

        return array_slice($hits, 0, max(0, $query->topK));
    }

    public function delete(string $namespace, array $ids): void
    {
        if ($ids === [] || ! $this->tableExists()) {
            return;
        }

        $this->conn()->table($this->table)->where('namespace', $namespace)->whereIn('id', $ids)->delete();
    }

    public function deleteByFilter(string $namespace, array $filter): void
    {
        if (! $this->tableExists()) {
            return;
        }

        $builder = $this->conn()->table($this->table)->where('namespace', $namespace);

        if (isset($filter['tenant_id']) && ! is_array($filter['tenant_id']) && count($filter) === 1) {
            $builder->where('tenant_id', $filter['tenant_id'])->delete();

            return;
        }

        if ($filter !== []) {
            $compiled = $this->filters->compile($filter);
            $builder->whereRaw('('.$compiled['sql'].')', $compiled['bindings']);
        }

        $ids = [];
        foreach ($builder->get() as $row) {
            /** @var array<string, mixed> $metadata */
            $metadata = json_decode((string) $row->metadata, true) ?: [];
            if (MetadataMatcher::matches($metadata, $filter)) {
                $ids[] = (string) $row->id;
            }
        }

        $this->delete($namespace, $ids);
    }

    public function count(string $namespace): int
    {
        if (! $this->tableExists()) {
            return 0;
        }

        return $this->conn()->table($this->table)->where('namespace', $namespace)->count();
    }

    public function name(): string
    {
        return 'pgvector';
    }

    /**
     * Make a filtered ANN query return the full LIMIT. `SET LOCAL` confines
     * every setting to the surrounding transaction.
     *
     * - HNSW returns at most `hnsw.ef_search` (default 40) candidates, so it
     *   is raised to cover the LIMIT.
     * - A metadata filter is applied AFTER the index scan. pgvector >= 0.8
     *   keeps scanning the index until the LIMIT is filled (iterative scans);
     *   older versions cannot, so filtered queries use an exact scan there
     *   (correct results over speed — upgrade pgvector for large corpora).
     */
    private function tuneIndexScan(ConnectionInterface $conn, int $limit, bool $filtered): void
    {
        if ($this->index === 'hnsw') {
            $efSearch = min(self::MAX_EF_SEARCH, max(40, $limit));
            $conn->statement("SET LOCAL hnsw.ef_search = {$efSearch}");
        }

        if (! $filtered) {
            return;
        }

        if (version_compare($this->extensionVersion($conn), '0.8.0', '<')) {
            $conn->statement('SET LOCAL enable_indexscan = off');

            return;
        }

        $conn->statement($this->index === 'ivfflat'
            ? 'SET LOCAL ivfflat.iterative_scan = relaxed_order'
            : 'SET LOCAL hnsw.iterative_scan = strict_order');
    }

    private function extensionVersion(ConnectionInterface $conn): string
    {
        if ($this->extensionVersion === null) {
            $row = $conn->selectOne("SELECT extversion FROM pg_extension WHERE extname = 'vector'");
            $this->extensionVersion = is_object($row) && is_string($row->extversion ?? null) ? $row->extversion : '0.0.0';
        }

        return $this->extensionVersion;
    }

    private function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }

        [, $opclass] = self::METRICS[$this->metric];
        $conn = $this->conn();

        $conn->statement('CREATE EXTENSION IF NOT EXISTS vector');
        $conn->statement(
            "CREATE TABLE IF NOT EXISTS {$this->table} ("
            .'id text PRIMARY KEY, '
            .'namespace text NOT NULL, '
            .'tenant_id text, '
            ."embedding vector({$this->dimensions}) NOT NULL, "
            .'metadata jsonb, '
            .'content text'
            .')'
        );
        $conn->statement("CREATE INDEX IF NOT EXISTS {$this->table}_ns_idx ON {$this->table} (namespace, tenant_id)");
        $conn->statement(
            "CREATE INDEX IF NOT EXISTS {$this->table}_emb_idx ON {$this->table} USING {$this->index} (embedding {$opclass})"
        );

        $this->schemaReady = true;
    }

    private function tableExists(): bool
    {
        if ($this->schemaReady) {
            return true;
        }

        $exists = $this->conn()->selectOne('SELECT to_regclass(?) AS reg', [$this->table]);

        return $exists !== null && ($exists->reg ?? null) !== null;
    }

    /**
     * @param  list<float>  $vector
     */
    private function toVector(array $vector): string
    {
        return '['.implode(',', array_map(static fn (float $v): string => (string) $v, $vector)).']';
    }

    /**
     * @return list<float>
     */
    private function parseVector(string $text): array
    {
        $trimmed = trim($text, "[] \t\n\r");
        if ($trimmed === '') {
            return [];
        }

        return array_map('floatval', explode(',', $trimmed));
    }

    private function scoreFromDistance(float $distance): float
    {
        return match ($this->metric) {
            'cosine' => 1.0 - $distance,    // cosine distance in [0,2] → similarity
            'l2' => 1.0 / (1.0 + $distance),
            'dot' => -$distance,            // <#> returns the negative inner product
            default => -$distance,
        };
    }

    private function conn(): ConnectionInterface
    {
        return $this->db->connection($this->connection);
    }
}
