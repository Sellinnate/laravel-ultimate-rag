<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Recovery;

use Sellinnate\RagEngine\Models\Chunk;
use Sellinnate\RagEngine\Models\EmbeddingRecord;

/**
 * Vectors ↔ metadata consistency reconciliation (NFR-DR-02). Detects child
 * chunks without an embedding record (never indexed) and embedding records
 * whose chunk no longer exists (orphans), so they can be re-indexed or pruned.
 */
final class Reconciler
{
    /**
     * @return array{missing_embeddings: list<string>, orphan_embeddings: list<string>}
     */
    public function reconcile(string $tenantId): array
    {
        // Child chunks (those meant to be embedded) lacking an embedding record.
        $embeddedChunkIds = EmbeddingRecord::query()
            ->where('tenant_id', $tenantId)
            ->pluck('chunk_id')
            ->all();

        $missing = Chunk::query()
            ->where('tenant_id', $tenantId)
            ->where('metadata->is_parent', false)
            ->whereNotIn('id', $embeddedChunkIds === [] ? ['__none__'] : $embeddedChunkIds)
            ->pluck('id')
            ->all();

        // Embedding records whose chunk row is gone.
        $existingChunkIds = Chunk::query()->where('tenant_id', $tenantId)->pluck('id')->all();
        $orphans = EmbeddingRecord::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('chunk_id', $existingChunkIds === [] ? ['__none__'] : $existingChunkIds)
            ->pluck('chunk_id')
            ->all();

        return [
            'missing_embeddings' => array_values(array_map('strval', $missing)),
            'orphan_embeddings' => array_values(array_map('strval', $orphans)),
        ];
    }

    /**
     * Delete a tenant's orphan embedding records (their chunk row is gone).
     *
     * @return int Number of records removed.
     */
    public function pruneOrphans(string $tenantId): int
    {
        $orphans = $this->reconcile($tenantId)['orphan_embeddings'];
        $removed = 0;

        foreach (array_chunk($orphans, 500) as $batch) {
            $removed += EmbeddingRecord::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('chunk_id', $batch)
                ->delete();
        }

        return $removed;
    }

    public function isConsistent(string $tenantId): bool
    {
        $report = $this->reconcile($tenantId);

        return $report['missing_embeddings'] === [] && $report['orphan_embeddings'] === [];
    }
}
