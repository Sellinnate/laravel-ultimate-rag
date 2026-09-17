<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\VectorStore;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Sellinnate\RagEngine\Contracts\VectorStore;
use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Data\VectorRecord;
use Sellinnate\RagEngine\Exceptions\RagException;

/**
 * Qdrant vector store driver (FR-VS-01, primary). Self-hostable in the EU.
 * Collections map to namespaces; filters translate to Qdrant's filter DSL.
 */
final class QdrantStore implements VectorStore
{
    private const METRICS = ['cosine' => 'Cosine', 'dot' => 'Dot', 'l2' => 'Euclid'];

    /** @var array<string, string> namespace => Qdrant distance name */
    private array $metricCache = [];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $host,
        private readonly ?string $apiKey = null,
        private readonly string $defaultMetric = 'cosine',
        private readonly ?string $quantization = null,
    ) {}

    public function createNamespace(string $namespace, int $dimensions, string $metric = 'cosine'): void
    {
        if (! isset(self::METRICS[$metric])) {
            throw new RagException("Unsupported distance metric [{$metric}].");
        }

        $this->metricCache[$namespace] = self::METRICS[$metric];

        if ($this->namespaceExists($namespace)) {
            return;
        }

        $body = [
            'vectors' => ['size' => $dimensions, 'distance' => self::METRICS[$metric]],
        ];

        $quantizationConfig = $this->quantizationConfig();
        if ($quantizationConfig !== null) {
            $body['quantization_config'] = $quantizationConfig;
        }

        $this->request()->put("/collections/{$this->ns($namespace)}", $body)->throw();
    }

    /**
     * Translate the configured shorthand into Qdrant's quantization_config, which
     * compresses stored vectors to cut memory/cost. `null` keeps full precision.
     *
     * @return array<string, mixed>|null
     */
    private function quantizationConfig(): ?array
    {
        return match ($this->quantization) {
            'scalar' => ['scalar' => ['type' => 'int8', 'always_ram' => true]],
            'binary' => ['binary' => ['always_ram' => true]],
            default => null,
        };
    }

    public function namespaceExists(string $namespace): bool
    {
        return $this->request()->get("/collections/{$this->ns($namespace)}")->successful();
    }

    public function deleteNamespace(string $namespace): void
    {
        $this->request()->delete("/collections/{$this->ns($namespace)}");
    }

    public function upsert(string $namespace, array $records): void
    {
        if ($records === []) {
            return;
        }

        $points = array_map(static fn (VectorRecord $r): array => [
            'id' => $r->id,
            'vector' => $r->vector,
            'payload' => $r->metadata,
        ], $records);

        $this->request()->put("/collections/{$this->ns($namespace)}/points?wait=true", ['points' => $points])->throw();
    }

    public function search(string $namespace, array $vector, RetrievalQuery $query): array
    {
        $body = [
            'vector' => $vector,
            'limit' => max(1, $query->topK),
            'with_payload' => true,
            'with_vector' => true,
        ];

        $filter = $this->buildFilter($query);
        if ($filter === null) {
            // An empty IN list can never match (e.g. a user with no scopes).
            return [];
        }
        if ($filter !== []) {
            $body['filter'] = $filter;
        }

        // Forward the threshold only when Qdrant's "higher is better" matches our
        // convention (cosine/dot). For Euclid we normalize client-side instead.
        if ($query->scoreThreshold !== null && $this->metricFor($namespace) !== 'Euclid') {
            $body['score_threshold'] = $query->scoreThreshold;
        }

        $response = $this->request()->post("/collections/{$this->ns($namespace)}/points/search", $body);

        if (! $response->successful()) {
            throw new RagException("Qdrant search failed: HTTP {$response->status()}.");
        }

        // Euclid returns a distance (lower = better); normalize it to a positive
        // higher-is-better score so the convention matches the in-memory driver.
        $isEuclid = $this->metricFor($namespace) === 'Euclid';

        return array_values(array_map(static function (array $point) use ($isEuclid): SearchHit {
            /** @var array<string, mixed> $payload */
            $payload = $point['payload'] ?? [];
            $raw = (float) ($point['score'] ?? 0.0);

            return new SearchHit(
                id: (string) $point['id'],
                score: $isEuclid ? 1.0 / (1.0 + $raw) : $raw,
                content: (string) ($payload['content'] ?? ''),
                metadata: $payload,
                documentId: isset($payload['document_id']) ? (string) $payload['document_id'] : null,
                chunkId: isset($payload['chunk_id']) ? (string) $payload['chunk_id'] : null,
                vector: isset($point['vector']) && is_array($point['vector'])
                    ? array_values(array_map('floatval', $point['vector']))
                    : null,
            );
        }, $response->json('result') ?? []));
    }

    public function delete(string $namespace, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->request()->post("/collections/{$this->ns($namespace)}/points/delete?wait=true", ['points' => $ids]);
    }

    public function deleteByFilter(string $namespace, array $filter): void
    {
        $qdrantFilter = $this->translateFilter($filter);

        // Never send an empty filter (it would delete everything), and an
        // unsatisfiable one matches nothing.
        if ($qdrantFilter === null || $qdrantFilter === []) {
            return;
        }

        $this->request()->post("/collections/{$this->ns($namespace)}/points/delete?wait=true", [
            'filter' => $qdrantFilter,
        ]);
    }

    public function count(string $namespace): int
    {
        $response = $this->request()->post("/collections/{$this->ns($namespace)}/points/count", ['exact' => true]);

        return (int) ($response->json('result.count') ?? 0);
    }

    public function name(): string
    {
        return 'qdrant';
    }

    /**
     * @return array<string, mixed>|null Null when the filter can never match.
     */
    private function buildFilter(RetrievalQuery $query): ?array
    {
        $filters = $query->filters;

        if ($query->tenantId !== null) {
            $filters['tenant_id'] = $query->tenantId;
        }

        return $this->translateFilter($filters);
    }

    /**
     * Translate the shared filter grammar (see MetadataMatcher) into Qdrant's
     * filter DSL: scalars and `eq` → match, lists and `in` → match any,
     * `neq`/`nin` → must_not, `null` → is_empty, gt/gte/lt/lte → numeric range.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, list<array<string, mixed>>>|null Null when unsatisfiable (empty IN list).
     */
    private function translateFilter(array $filters): ?array
    {
        $must = [];
        $mustNot = [];

        foreach ($filters as $key => $value) {
            $key = (string) $key;

            if (! is_array($value)) {
                $must[] = $this->equals($key, $value);

                continue;
            }

            if (array_is_list($value)) {
                $condition = $this->anyOf($key, $value);
                if ($condition === null) {
                    return null;
                }
                $must[] = $condition;

                continue;
            }

            $range = [];
            foreach ($value as $op => $operand) {
                switch ($op) {
                    case 'gt':
                    case 'gte':
                    case 'lt':
                    case 'lte':
                        if (! is_int($operand) && ! is_float($operand)) {
                            throw new RagException("Qdrant range filters need a numeric operand for [{$key}].");
                        }
                        $range[$op] = $operand;
                        break;
                    case 'eq':
                        $must[] = $this->equals($key, $operand);
                        break;
                    case 'neq':
                        $mustNot[] = $this->equals($key, $operand);
                        break;
                    case 'in':
                        $condition = $this->anyOf($key, $this->listOperand($key, $op, $operand));
                        if ($condition === null) {
                            return null;
                        }
                        $must[] = $condition;
                        break;
                    case 'nin':
                        $condition = $this->anyOf($key, $this->listOperand($key, $op, $operand));
                        if ($condition !== null) {
                            $mustNot[] = $condition;
                        }
                        break;
                    default:
                        throw new RagException("Unsupported Qdrant filter operator [{$op}].");
                }
            }

            if ($range !== []) {
                $must[] = ['key' => $key, 'range' => $range];
            }
        }

        return array_filter(['must' => $must, 'must_not' => $mustNot], static fn (array $conditions): bool => $conditions !== []);
    }

    /**
     * @return array<string, mixed>
     */
    private function equals(string $key, mixed $value): array
    {
        return $value === null
            ? ['is_empty' => ['key' => $key]]
            : ['key' => $key, 'match' => ['value' => $value]];
    }

    /**
     * Membership condition; null for an empty list (matches nothing).
     *
     * @param  array<mixed>  $values
     * @return array<string, mixed>|null
     */
    private function anyOf(string $key, array $values): ?array
    {
        $values = array_values($values);
        $nonNull = array_values(array_filter($values, static fn (mixed $v): bool => $v !== null));

        if ($values === []) {
            return null;
        }

        $match = ['key' => $key, 'match' => ['any' => $nonNull]];

        if (count($nonNull) === count($values)) {
            return $match;
        }

        $empty = ['is_empty' => ['key' => $key]];

        return $nonNull === [] ? $empty : ['should' => [$match, $empty]];
    }

    /**
     * @return array<mixed>
     */
    private function listOperand(string $key, string $op, mixed $operand): array
    {
        if (! is_array($operand)) {
            throw new RagException("The [{$op}] filter on [{$key}] needs a list.");
        }

        return $operand;
    }

    /**
     * Validate a collection name before interpolating it into a URL path,
     * blocking path/query injection (e.g. '../../collections/other').
     */
    /**
     * The Qdrant distance name for a namespace: the value learned when the
     * namespace was created in this instance, else the configured default. No
     * extra round-trip per search.
     */
    private function metricFor(string $namespace): string
    {
        return $this->metricCache[$namespace] ?? (self::METRICS[$this->defaultMetric] ?? 'Cosine');
    }

    private function ns(string $namespace): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $namespace) !== 1) {
            throw new RagException("Invalid namespace [{$namespace}]: only [A-Za-z0-9_-]{1,64} allowed.");
        }

        return $namespace;
    }

    private function request(): PendingRequest
    {
        $request = $this->http->baseUrl(rtrim($this->host, '/'))->acceptJson()->asJson()->timeout(15);

        return $this->apiKey !== null && $this->apiKey !== ''
            ? $request->withHeaders(['api-key' => $this->apiKey])
            : $request;
    }
}
