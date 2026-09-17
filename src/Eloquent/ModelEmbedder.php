<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Eloquent;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Sellinnate\RagEngine\Contracts\Embeddable;
use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Ingestion\Ingestor;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Pipeline\IngestionPipeline;
use Sellinnate\RagEngine\Tenancy\TenantContext;

/**
 * Bridges Eloquent models and the RAG index (FR-DX-05).
 *
 * - {@see sync()} composes an {@see Embeddable} (recursively), ingests it as a
 *   versioned document keyed by the model's stable identity, indexes it, and
 *   purges superseded generations so a field change never leaves stale vectors.
 * - {@see forget()} removes every trace of a model from the index (delete).
 * - {@see resolve()} walks back from a {@see SearchHit}/{@see Document} to the
 *   originating Eloquent model — vectors are always traceable to their source.
 *
 * The model's identity (`type:id`, morph-map aware) is written both to the
 * document metadata and into every vector payload, so trace-back works straight
 * from a retrieved vector without a second lookup.
 */
final class ModelEmbedder
{
    public function __construct(
        private readonly Ingestor $ingestor,
        private readonly IngestionPipeline $pipeline,
        private readonly EmbeddableCompiler $compiler,
        private readonly TenantContext $tenant,
        private readonly Config $config,
    ) {}

    /**
     * Index (or re-index) a model and its composed relations.
     *
     * @param  array<string, mixed>  $options
     */
    public function sync(Model $model, array $options = []): ?Document
    {
        $embeddable = $this->asEmbeddable($model);
        $compiled = $this->compiler->compile($embeddable);
        $key = $compiled->documentKey;

        // Nothing embeddable (all fields blank): make sure no stale doc lingers.
        if ($compiled->isEmpty()) {
            $this->forgetByKey($key);

            return null;
        }

        $identity = $this->identity($model, $key);
        $namespace = $this->namespace($options);

        // `force` re-processes even when nothing changed (e.g. `rag:reindex`).
        $force = (bool) ($options['force'] ?? false);
        unset($options['force']);

        // The declared title (EmbeddableDefinition::title()) becomes the
        // document's `title`, which heads every chunk's contextual header.
        $declared = $compiled->title !== null
            ? [...$compiled->metadata, 'title' => $compiled->title]
            : $compiled->metadata;

        $source = new IngestionSource(
            content: $compiled->content,
            mimeType: 'text/plain',
            sourceType: IngestionSource::TYPE_ELOQUENT,
            metadata: [
                ...$declared,
                'document_key' => $key,
                'embeddable_type' => $identity['type'],
                'embeddable_id' => $identity['id'],
                'embeddable_key' => $key,
                'included_keys' => $compiled->includedKeys,
                // Propagated into every vector payload (FR-RT-06): the declared
                // filterable metadata (e.g. an access `scope`) plus the model
                // identity, so a retrieved vector can be filtered on and traced
                // back to its model with no extra query. Identity keys win.
                'rag_vector_metadata' => [
                    // The title is left out: chunks carry it PII-redacted, and a
                    // raw copy here would override that in the vector payload.
                    ...self::filterableMetadata(array_diff_key($declared, ['title' => true])),
                    'embeddable_type' => $identity['type'],
                    'embeddable_id' => $identity['id'],
                    'embeddable_key' => $key,
                ],
            ],
        );

        $document = $this->ingestor->ingest($source);

        // Re-process only when something actually changed — new content, or
        // changed vector metadata (the ingestor flags the document `pending`) —
        // keeping save-driven syncs cheap.
        if ($force || $document->wasRecentlyCreated || $document->status !== 'indexed') {
            $this->pipeline->process($document, ['namespace' => $namespace, ...$compiled->options, ...$options]);
        }

        // Drop superseded generations (and their vectors) for this model.
        $this->purgeOthers($key, (string) $document->id);

        return $document;
    }

    /**
     * Remove a model from the index entirely (handles deletion).
     */
    public function forget(Model $model): void
    {
        $identity = $this->identity($model);

        $this->forgetByIdentity($identity['type'], $identity['id']);
    }

    /**
     * Remove a model from the index by its morph identity (for queued deletes,
     * where the row may already be gone). Matches both the default `type:id`
     * document key and the stored `embeddable_*` identity, so models with a
     * custom {@see EmbeddableDefinition::documentKey()} are removed too.
     */
    public function forgetByIdentity(string $type, string $id): void
    {
        Document::query()
            ->where('tenant_id', $this->tenant->id())
            ->where(fn ($query) => $query
                ->where('metadata->document_key', $type.':'.$id)
                ->orWhere(fn ($identity) => $identity
                    ->where('metadata->embeddable_type', $type)
                    ->where('metadata->embeddable_id', $id)))
            ->get()
            ->each(fn (Document $document) => $this->ingestor->purge($document));
    }

    /**
     * Trace a retrieved hit/document back to its originating Eloquent model.
     * Returns null when the source was not a model (e.g. a URL or file) or the
     * model no longer exists.
     *
     * @param  SearchHit|Document|array<string, mixed>  $subject
     */
    public function resolve(SearchHit|Document|array $subject): ?Model
    {
        $metadata = $this->metadataOf($subject);

        $type = $metadata['embeddable_type'] ?? null;
        $id = $metadata['embeddable_id'] ?? null;

        // A hit may predate this metadata; fall back to its document row.
        if (($type === null || $id === null) && $subject instanceof SearchHit && $subject->documentId !== null) {
            $document = Document::query()->find($subject->documentId);

            if ($document instanceof Document) {
                $documentMeta = (array) $document->metadata;
                $type = $documentMeta['embeddable_type'] ?? null;
                $id = $documentMeta['embeddable_id'] ?? null;
            }
        }

        if (! is_string($type) || ! is_scalar($id)) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->whereKey($id)->first();
    }

    /**
     * The subset of declared metadata that can live in a vector payload and be
     * filtered on: scalar values and lists of scalars. Nulls, maps and objects
     * stay on the document only.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, scalar|list<scalar>>
     */
    public static function filterableMetadata(array $metadata): array
    {
        $filterable = [];

        foreach ($metadata as $key => $value) {
            if (is_scalar($value)) {
                $filterable[$key] = $value;

                continue;
            }

            if (! is_array($value) || ! array_is_list($value)) {
                continue;
            }

            $items = [];
            foreach ($value as $item) {
                if (! is_scalar($item)) {
                    continue 2;
                }
                $items[] = $item;
            }

            $filterable[$key] = $items;
        }

        return $filterable;
    }

    private function asEmbeddable(Model $model): Embeddable
    {
        if (! $model instanceof Embeddable) {
            throw new RagException(sprintf(
                'Model [%s] must implement %s to be embedded.',
                $model::class,
                Embeddable::class,
            ));
        }

        return $model;
    }

    private function purgeOthers(string $key, string $keepId): void
    {
        Document::query()
            ->where('tenant_id', $this->tenant->id())
            ->where('metadata->document_key', $key)
            ->where('id', '!=', $keepId)
            ->get()
            ->each(fn (Document $document) => $this->ingestor->purge($document));
    }

    private function forgetByKey(string $key): void
    {
        Document::query()
            ->where('tenant_id', $this->tenant->id())
            ->where('metadata->document_key', $key)
            ->get()
            ->each(fn (Document $document) => $this->ingestor->purge($document));
    }

    /**
     * @return array{type: string, id: string, key: string}
     */
    private function identity(Model $model, ?string $key = null): array
    {
        $type = $model->getMorphClass();
        $id = is_scalar($model->getKey()) ? (string) $model->getKey() : '';

        return ['type' => $type, 'id' => $id, 'key' => $key ?? $type.':'.$id];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function namespace(array $options): string
    {
        if (isset($options['namespace']) && is_string($options['namespace'])) {
            return $options['namespace'];
        }

        $configured = $this->config->get('rag-engine.eloquent.namespace');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return (string) $this->config->get('rag-engine.namespace', 'documents');
    }

    /**
     * @param  SearchHit|Document|array<string, mixed>  $subject
     * @return array<string, mixed>
     */
    private function metadataOf(SearchHit|Document|array $subject): array
    {
        return match (true) {
            $subject instanceof SearchHit => $subject->metadata,
            $subject instanceof Document => (array) $subject->metadata,
            default => $subject,
        };
    }
}
