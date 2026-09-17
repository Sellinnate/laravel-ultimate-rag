<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Sellinnate\RagEngine\Console\Concerns\NormalizesInput;
use Sellinnate\RagEngine\Contracts\Embeddable;
use Sellinnate\RagEngine\Eloquent\ModelEmbedder;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Pipeline\IngestionPipeline;
use Sellinnate\RagEngine\Tenancy\TenantContext;
use Throwable;

/**
 * Rebuild a tenant's vectors from its stored (encrypted) documents.
 *
 * Use it after an upgrade that changes what a vector payload holds — e.g. to
 * strip legacy plaintext `content` (`security.vector_payload_content`) or to
 * add newly propagated filterable metadata to existing vectors. Eloquent
 * documents are re-synced from their live model (so current metadata is
 * applied); a document whose model is gone is removed from the index. Every
 * document stays in the namespace it was indexed into.
 */
final class ReindexCommand extends Command
{
    use NormalizesInput;

    protected $signature = 'rag:reindex {tenant : The tenant id}';

    protected $description = "Rebuild a tenant's vectors from its stored documents (after upgrades)";

    public function handle(TenantContext $tenant, IngestionPipeline $pipeline, ModelEmbedder $models): int
    {
        $tenantId = $this->stringArgument('tenant');

        $counts = $tenant->runAs($tenantId, function () use ($tenantId, $pipeline, $models): array {
            $counts = ['reindexed' => 0, 'removed' => 0, 'failed' => 0];

            // Snapshot the ids first: re-syncing a model can create a new
            // document version, which must not be visited a second time.
            $ids = Document::query()
                ->where('tenant_id', $tenantId)
                ->whereNull('soft_deleted_at')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            foreach ($ids as $id) {
                $document = Document::query()->whereKey($id)->whereNull('soft_deleted_at')->first();

                if (! $document instanceof Document) {
                    continue;
                }

                try {
                    $counts[$this->reindex($document, $pipeline, $models)]++;
                } catch (Throwable $e) {
                    $counts['failed']++;
                    $this->error("Document [{$document->id}]: {$e->getMessage()}");
                }
            }

            return $counts;
        });

        $this->table(['Result', 'Documents'], [
            ['Re-indexed', $counts['reindexed']],
            ['Removed (model gone)', $counts['removed']],
            ['Failed', $counts['failed']],
        ]);

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return 'reindexed'|'removed'
     */
    private function reindex(Document $document, IngestionPipeline $pipeline, ModelEmbedder $models): string
    {
        $options = $document->indexed_namespace !== null ? ['namespace' => $document->indexed_namespace] : [];
        $metadata = $document->metadata ?? [];
        $type = $metadata['embeddable_type'] ?? null;
        $id = $metadata['embeddable_id'] ?? null;

        if ($document->source_type !== IngestionSource::TYPE_ELOQUENT || ! is_string($type) || ! is_scalar($id)) {
            $pipeline->process($document, $options);

            return 'reindexed';
        }

        $model = $models->resolve($document);

        if ($model instanceof Model && $model instanceof Embeddable) {
            $models->sync($model, [...$options, 'force' => true]);

            return 'reindexed';
        }

        $models->forgetByIdentity($type, (string) $id);

        return 'removed';
    }
}
