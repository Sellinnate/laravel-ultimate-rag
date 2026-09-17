<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Retrieval;

use JsonException;
use Sellinnate\RagEngine\Contracts\Tokenizer;
use Sellinnate\RagEngine\Contracts\VectorStore;
use Sellinnate\RagEngine\Data\EncryptedPayload;
use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Embedding\EmbeddingService;
use Sellinnate\RagEngine\Events\SearchPerformed;
use Sellinnate\RagEngine\Managers\LlmManager;
use Sellinnate\RagEngine\Managers\RerankerManager;
use Sellinnate\RagEngine\Managers\VectorStoreManager;
use Sellinnate\RagEngine\Models\Chunk;
use Sellinnate\RagEngine\Query\MultiQueryTransformer;
use Sellinnate\RagEngine\Security\EnvelopeEncrypter;
use Sellinnate\RagEngine\Tenancy\TenantContext;

/**
 * Executes a {@see SearchRequest} end to end (FR-RT). Tenant scoping is
 * MANDATORY and fail-closed: scope is ALWAYS the ambient {@see TenantContext} —
 * a request cannot widen it. To search another tenant use the authorized
 * `Rag::forTenant()` (which sets the context and restores it after), never a
 * caller-supplied tenant on the query.
 */
final class Retriever
{
    public function __construct(
        private readonly EmbeddingService $embedding,
        private readonly VectorStoreManager $stores,
        private readonly RerankerManager $rerankers,
        private readonly TenantContext $tenant,
        private readonly Tokenizer $tokenizer,
        private readonly EnvelopeEncrypter $encrypter,
        private readonly LlmManager $llms,
        private readonly Rrf $rrf = new Rrf,
        private readonly Mmr $mmr = new Mmr,
        private readonly KeywordScorer $keyword = new KeywordScorer,
    ) {}

    /**
     * Resolve the query (or its multi-query expansions) to retrieve for.
     *
     * @return list<string>
     */
    private function resolveQueries(SearchRequest $request): array
    {
        if (! $request->expandQueries) {
            return [$request->text];
        }

        // With the null LLM this degrades to just the original query.
        $transformer = new MultiQueryTransformer($this->llms->driver($request->llm), $request->queryVariations);

        return $transformer->transform($request->text);
    }

    /**
     * Retrieve a single query's candidate pool: vector search plus, when hybrid
     * is on, a BM25 keyword ranking fused via RRF.
     *
     * @return list<SearchHit>
     */
    private function gatherCandidates(string $queryText, SearchRequest $request, VectorStore $store, string $tenantId, int $fetchK): array
    {
        $vector = $this->embedding->embed([$queryText], $request->embedder)->vectorAt(0);

        $query = new RetrievalQuery(
            text: $queryText,
            topK: $fetchK,
            filters: $request->filters,
            namespace: $request->namespace,
            tenantId: $tenantId,
        );

        $candidates = $this->hydrate($store->search($request->namespace, $vector, $query), $tenantId);

        if ($request->hybrid) {
            $keywordRanking = $this->keyword->rank($queryText, $candidates, $fetchK);
            $candidates = $this->rrf->fuse([$candidates, $keywordRanking], $fetchK);
        }

        return $candidates;
    }

    /**
     * Fill in hit content that is not stored in the vector payload (the secure
     * default when encryption is on) by decrypting the matching chunk rows —
     * one batched, tenant-scoped query. Legacy hits that still carry payload
     * content are used as-is. Hits whose chunk row is gone (orphan vectors) or
     * belongs to another tenant are dropped: nothing surfaces without its
     * encrypted source of truth.
     *
     * @param  list<SearchHit>  $hits
     * @return list<SearchHit>
     */
    private function hydrate(array $hits, string $tenantId): array
    {
        $missing = [];
        foreach ($hits as $hit) {
            if ($hit->content === '') {
                $missing[] = $hit->chunkId ?? $hit->id;
            }
        }

        if ($missing === []) {
            return $hits;
        }

        $rows = Chunk::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', array_values(array_unique($missing)))
            ->get(['id', 'encrypted_content', 'content']);

        $contents = [];
        foreach ($rows as $row) {
            $contents[(string) $row->id] = $this->chunkText($row);
        }

        $hydrated = [];
        foreach ($hits as $hit) {
            if ($hit->content !== '') {
                $hydrated[] = $hit;

                continue;
            }

            $content = $contents[$hit->chunkId ?? $hit->id] ?? null;

            if ($content !== null) {
                $hydrated[] = $hit->withContent($content);
            }
        }

        return $hydrated;
    }

    private function chunkText(Chunk $chunk): ?string
    {
        if ($chunk->encrypted_content === null) {
            return $chunk->content;
        }

        try {
            $payload = json_decode($chunk->encrypted_content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null; // malformed row: discard the hit, keep the search going
        }

        if (! is_array($payload)
            || ! is_string($payload['ciphertext'] ?? null)
            || ! is_string($payload['wrapped_dek'] ?? null)
            || ! is_string($payload['key_id'] ?? null)) {
            return null;
        }

        // Decryption failures (e.g. a shredded key) are NOT suppressed.
        return $this->encrypter->decrypt(new EncryptedPayload($payload['ciphertext'], $payload['wrapped_dek'], $payload['key_id']));
    }

    /**
     * @return list<SearchHit>
     */
    public function retrieve(SearchRequest $request): array
    {
        // Fail-closed tenant scoping: ALWAYS the ambient tenant, never the caller's.
        $tenantId = $this->tenant->id();
        $store = $this->stores->driver($request->store);
        $fetchK = $request->effectiveFetchK();

        // The MMR diversification step compares against the ORIGINAL query.
        $queryVector = $this->embedding->embed([$request->text], $request->embedder)->vectorAt(0);

        // Multi-query expansion (FR-QT-01): retrieve each phrasing and RRF-fuse.
        $queries = $this->resolveQueries($request);

        $candidateLists = [];
        foreach ($queries as $queryText) {
            $candidateLists[] = $this->gatherCandidates($queryText, $request, $store, $tenantId, $fetchK);
        }

        $candidates = count($candidateLists) > 1
            ? $this->rrf->fuse($candidateLists, $fetchK)
            : ($candidateLists[0] ?? []);

        if ($request->rerank) {
            $candidates = $this->rerankers->driver($request->rerankerName)->rerank($request->text, $candidates, $fetchK);
        }

        // Filter the candidate POOL before the final top-k selection so unique,
        // above-threshold results backfill to topK (no under-filling).
        if ($request->scoreThreshold !== null) {
            $candidates = array_values(array_filter(
                $candidates,
                static fn (SearchHit $h): bool => $h->score >= $request->scoreThreshold,
            ));
        }

        if ($request->dedup) {
            $candidates = $this->dedupe($candidates);
        }

        $hits = $request->mmr
            ? $this->applyMmr($queryVector, $candidates, $request->topK, $request->mmrLambda)
            : array_slice($candidates, 0, max(0, $request->topK));

        if ($request->expandParents) {
            $hits = $this->expandParents($hits, $tenantId);
        }

        if ($request->contextBudgetTokens !== null) {
            $hits = $this->applyBudget($hits, $request->contextBudgetTokens);
        }

        event(new SearchPerformed($tenantId, $request->text, count($hits)));

        return $hits;
    }

    /**
     * @param  list<float>  $queryVector
     * @param  list<SearchHit>  $candidates
     * @return list<SearchHit>
     */
    private function applyMmr(array $queryVector, array $candidates, int $topK, float $lambda): array
    {
        $withVectors = [];
        foreach ($candidates as $hit) {
            if ($hit->vector !== null) {
                $withVectors[] = ['hit' => $hit, 'vector' => $hit->vector];
            }
        }

        // If the backend didn't return vectors, MMR can't run — fall back.
        if ($withVectors === []) {
            return array_slice($candidates, 0, $topK);
        }

        return $this->mmr->select($queryVector, $withVectors, $topK, $lambda);
    }

    /**
     * Result deduplication by content (FR-RR-03).
     *
     * @param  list<SearchHit>  $hits
     * @return list<SearchHit>
     */
    private function dedupe(array $hits): array
    {
        $seen = [];
        $out = [];

        foreach ($hits as $hit) {
            $trimmed = trim($hit->content);

            // Don't collapse empty/whitespace-only hits together — they may be
            // distinct chunks; only dedup hits with identical non-empty content.
            if ($trimmed === '') {
                $out[] = $hit;

                continue;
            }

            $key = hash('sha256', $trimmed);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $hit;
        }

        return $out;
    }

    /**
     * Parent-context expansion for small-to-big retrieval (FR-RT-07).
     *
     * @param  list<SearchHit>  $hits
     * @return list<SearchHit>
     */
    private function expandParents(array $hits, string $tenantId): array
    {
        return array_map(function (SearchHit $hit) use ($tenantId): SearchHit {
            $parentId = $hit->metadata['parent_chunk_id'] ?? null;

            if (! is_string($parentId)) {
                return $hit;
            }

            $parent = Chunk::query()->where('id', $parentId)->where('tenant_id', $tenantId)->first();

            if (! $parent instanceof Chunk || $parent->encrypted_content === null) {
                return $hit;
            }

            /** @var array<string, string> $payload */
            $payload = json_decode($parent->encrypted_content, true);
            $parentContent = $this->encrypter->decrypt(EncryptedPayload::fromArray($payload));

            return $hit->withMetadata(['parent_content' => $parentContent]);
        }, $hits);
    }

    /**
     * Token-aware context budget (FR-RR-04): keep hits until the budget is spent.
     *
     * @param  list<SearchHit>  $hits
     * @return list<SearchHit>
     */
    private function applyBudget(array $hits, int $budgetTokens): array
    {
        $spent = 0;
        $kept = [];

        foreach ($hits as $hit) {
            // Budget against the text that will actually be consumed: the expanded
            // parent content when present (FR-RT-07), else the chunk content.
            $consumed = isset($hit->metadata['parent_content']) && is_string($hit->metadata['parent_content'])
                ? $hit->metadata['parent_content']
                : $hit->content;

            $tokens = ($consumed === $hit->content && is_int($hit->metadata['token_count'] ?? null))
                ? $hit->metadata['token_count']
                : $this->tokenizer->count($consumed);

            if ($kept !== [] && $spent + $tokens > $budgetTokens) {
                break;
            }

            $kept[] = $hit;
            $spent += $tokens;
        }

        return $kept;
    }
}
