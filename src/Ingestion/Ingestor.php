<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Ingestion;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Sellinnate\RagEngine\Data\EncryptedPayload;
use Sellinnate\RagEngine\Events\DataShredded;
use Sellinnate\RagEngine\Events\DocumentIngested;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Managers\VectorStoreManager;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Models\EmbeddingRecord;
use Sellinnate\RagEngine\Models\ShreddedTenant;
use Sellinnate\RagEngine\Security\EnvelopeEncrypter;
use Sellinnate\RagEngine\Tenancy\TenantContext;
use Sellinnate\RagEngine\Tenancy\TenantQuota;

/**
 * Persists an {@see IngestionSource} as a {@see Document} with deduplication
 * (FR-IN-06), provenance (FR-IN-07), versioning (FR-IN-08), arbitrary metadata
 * (FR-IN-09), envelope encryption (FR-SEC-01) and soft-delete/purge (FR-IN-10).
 */
final class Ingestor
{
    /** Insert attempts for a keyed source racing identical content (see ingest()). */
    private const MAX_CREATE_ATTEMPTS = 3;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EnvelopeEncrypter $encrypter,
        private readonly Config $config,
        private readonly TenantQuota $quota,
        private readonly VectorStoreManager $stores,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata  Arbitrary metadata propagated to the document.
     */
    public function ingest(IngestionSource $source, array $metadata = []): Document
    {
        $this->assertWithinSizeLimit($source);

        $tenantId = $this->tenant->id();

        // Refuse re-provisioning a crypto-shredded tenant (FR-SEC-04).
        if (ShreddedTenant::query()->whereKey($tenantId)->exists()) {
            throw new RagException("Tenant [{$tenantId}] has been crypto-shredded and cannot be re-provisioned.");
        }

        $hash = $source->contentHash();
        $documentKey = $this->explicitDocumentKey($source);

        // Deduplication / idempotent re-ingestion (FR-IN-06). A source carrying an
        // explicit `document_key` (e.g. an Eloquent model) only dedupes against
        // its own logical document, so two records with identical text stay two
        // separately-scoped documents.
        $duplicateQuery = Document::query()
            ->where('tenant_id', $tenantId)
            ->where('content_hash', $hash)
            ->whereNull('soft_deleted_at');

        // …and a key-less source never claims a keyed document (e.g. a model's):
        // it could otherwise overwrite that document's access metadata.
        if ($documentKey !== null) {
            $duplicateQuery->where('metadata->document_key', $documentKey);
        } else {
            $duplicateQuery->whereNull('metadata->document_key');
        }

        $duplicate = $duplicateQuery->first();

        if ($duplicate instanceof Document) {
            return $this->refreshDuplicate($duplicate, $source, $metadata, $documentKey !== null);
        }

        // A keyed source can race a concurrent ingest of the same bytes under a
        // DIFFERENT key: both pick the same (tenant, hash, version) and one hits
        // the unique index without a winner of its own — retry with a fresh
        // version instead of failing.
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->create($source, $metadata, $tenantId, $hash);
            } catch (UniqueConstraintViolationException $e) {
                // Lost a race with a concurrent ingest of identical content (TOCTOU
                // between the dedup check and the insert). Return the winner.
                $winner = Document::query()
                    ->where('tenant_id', $tenantId)
                    ->where('content_hash', $hash)
                    ->whereNull('soft_deleted_at')
                    ->when(
                        $documentKey !== null,
                        fn ($query) => $query->where('metadata->document_key', $documentKey),
                        fn ($query) => $query->whereNull('metadata->document_key'),
                    )
                    ->orderByDesc('version')
                    ->first();

                if ($winner instanceof Document) {
                    return $this->refreshDuplicate($winner, $source, $metadata, $documentKey !== null);
                }

                if ($documentKey === null || $attempt >= self::MAX_CREATE_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function create(IngestionSource $source, array $metadata, string $tenantId, string $hash): Document
    {
        return DB::transaction(function () use ($source, $metadata, $tenantId, $hash): Document {
            // Quota enforced inside the write transaction to narrow the TOCTOU
            // window against concurrent ingests (FR-MT-04).
            $this->quota->assertCanIngest($tenantId, $source->size());

            $version = $this->resolveVersion($source, $tenantId, $hash);

            [$ref, $dekId] = $this->encryptContent($source->content, $tenantId);

            $document = Document::create([
                'tenant_id' => $tenantId,
                'source_type' => $source->sourceType,
                'content_hash' => $hash,
                'mime' => $source->mimeType,
                'size' => $source->size(),
                'metadata' => $this->buildMetadata($source, $metadata),
                'version' => $version,
                'status' => 'pending',
                'encrypted_content_ref' => $ref,
                'dek_id' => $dekId,
            ]);

            event(new DocumentIngested((string) $document->id, $tenantId));

            return $document;
        });
    }

    /**
     * Recover the original content of a document (decrypting if needed).
     */
    public function content(Document $document): string
    {
        // Defence-in-depth against a confused-deputy: never decrypt a document
        // outside its own tenant's context (M1).
        if ($document->tenant_id !== $this->tenant->id()) {
            throw new RagException(
                "Cannot decrypt document [{$document->id}]: it belongs to tenant [{$document->tenant_id}], "
                ."not the current tenant [{$this->tenant->id()}]."
            );
        }

        $ref = $document->encrypted_content_ref;

        if ($ref === null) {
            return '';
        }

        if ($document->dek_id === null) {
            // Stored as plaintext (encryption disabled).
            return (string) base64_decode($ref, true);
        }

        return $this->encrypter->decrypt(EncryptedPayload::fromArray((array) json_decode($ref, true)));
    }

    /**
     * Soft-delete a document, keeping it recoverable until purge (FR-IN-10).
     */
    public function softDelete(Document $document): void
    {
        $document->forceFill([
            'soft_deleted_at' => now(),
            'status' => 'deleted',
        ])->save();
    }

    /**
     * Physically purge a document. Deleting the row destroys the only copy of
     * the wrapped DEK, crypto-shredding the content at document granularity
     * (FR-IN-10, FR-SEC-04).
     */
    public function purge(Document $document): void
    {
        $tenantId = $document->tenant_id;
        $documentId = (string) $document->id;
        $wasEncrypted = $document->dek_id !== null;

        // Remove the document's vectors (which hold plaintext content in the
        // payload) from the namespace it was indexed into, not just the encrypted
        // DB rows (C2). Falls back to the configured default namespace.
        $namespace = $document->indexed_namespace ?? (string) $this->config->get('rag-engine.namespace', 'documents');
        $store = $this->stores->driver();
        if ($store->namespaceExists($namespace)) {
            $store->deleteByFilter($namespace, ['document_id' => $documentId]);
        }

        DB::transaction(function () use ($document, $tenantId): void {
            // Embedding bookkeeping has no FK to its chunk: remove it explicitly
            // so a purge never leaves orphan records behind (rag:reconcile).
            $chunkIds = $document->chunks()->pluck('id')->all();
            foreach (array_chunk($chunkIds, 500) as $batch) {
                EmbeddingRecord::query()->where('tenant_id', $tenantId)->whereIn('chunk_id', $batch)->delete();
            }

            $document->chunks()->delete();
            $document->delete();
        });

        if ($wasEncrypted) {
            // The shredded key is the per-document wrapped DEK that lived in the
            // now-deleted row — identify it by the document, not the shared KEK.
            event(new DataShredded($documentId, $tenantId, 'document'));
        }
    }

    /**
     * Metadata keys whose change alters what gets indexed even when the
     * content bytes are identical: the vector payload metadata, and the
     * `title` that heads every chunk's contextual header.
     *
     * @var list<string>
     */
    public const INDEXED_METADATA_KEYS = ['rag_vector_metadata', 'title'];

    /**
     * Keep an unchanged-content document in step with its incoming metadata.
     *
     * - A keyed duplicate (same logical document) takes the incoming metadata
     *   (provenance is kept).
     * - An un-keyed duplicate only takes explicitly supplied, different
     *   {@see INDEXED_METADATA_KEYS} values (last writer wins); other metadata
     *   is untouched.
     *
     * When an indexed key changed (e.g. an access scope or the title), the
     * document is flagged `pending` so the caller re-processes it and no vector
     * or contextual header stays stale.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function refreshDuplicate(Document $document, IngestionSource $source, array $metadata, bool $keyed): Document
    {
        $current = $document->metadata ?? [];
        $incoming = [...$source->metadata, ...$metadata];
        $supplied = array_intersect_key($incoming, array_flip(self::INDEXED_METADATA_KEYS));

        if (! $keyed && $supplied === []) {
            return $document;
        }

        if ($keyed) {
            // The logical document's latest declaration is authoritative (keys
            // it no longer declares are dropped). Provenance describes the
            // stored bytes, which did not change.
            $updated = $incoming;
            unset($updated['provenance']);
            if (array_key_exists('provenance', $current)) {
                $updated['provenance'] = $current['provenance'];
            }
        } else {
            $updated = [...$current, ...$supplied];
        }

        if (self::canonical($updated) === self::canonical($current)) {
            return $document;
        }

        $attributes = ['metadata' => $updated];

        if ($document->status === 'indexed' && self::indexedMetadataChanged($current, $updated)) {
            $attributes['status'] = 'pending';
        }

        $document->forceFill($attributes)->save();

        return $document;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private static function indexedMetadataChanged(array $before, array $after): bool
    {
        foreach (self::INDEXED_METADATA_KEYS as $key) {
            if (self::canonical($before[$key] ?? null) !== self::canonical($after[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function explicitDocumentKey(IngestionSource $source): ?string
    {
        $key = $source->metadata['document_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Order-insensitive (for maps) representation used to compare metadata.
     */
    private static function canonical(mixed $value): string
    {
        return json_encode(self::sortRecursive($value), JSON_THROW_ON_ERROR);
    }

    private static function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sortRecursive(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function resolveVersion(IngestionSource $source, string $tenantId, string $hash): int
    {
        $version = 1;
        $key = $source->documentKey();

        if ($key !== null) {
            $prior = Document::query()
                ->where('tenant_id', $tenantId)
                ->where('metadata->document_key', $key)
                ->whereNull('soft_deleted_at')
                ->orderByDesc('version')
                ->first();

            if ($prior instanceof Document) {
                // Supersede the prior version atomically (FR-AF-05).
                $prior->forceFill(['status' => 'superseded', 'soft_deleted_at' => now()])->save();
                $version = $prior->version + 1;
            }
        }

        // Keep (tenant_id, content_hash, version) unique even when an earlier
        // generation of the same content was soft-deleted, so re-ingesting after
        // a soft-delete produces a fresh, higher version.
        $maxForHash = Document::query()
            ->where('tenant_id', $tenantId)
            ->where('content_hash', $hash)
            ->max('version');

        if ($maxForHash !== null) {
            $version = max($version, (int) $maxForHash + 1);
        }

        return $version;
    }

    /**
     * @return array{0: string, 1: string|null} [ref, dekId]
     */
    private function encryptContent(string $content, string $tenantId): array
    {
        if (! $this->config->get('rag-engine.security.encryption_enabled', true)) {
            return [base64_encode($content), null];
        }

        $payload = $this->encrypter->encrypt($content, $tenantId);

        return [json_encode($payload->toArray(), JSON_THROW_ON_ERROR), $tenantId];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function buildMetadata(IngestionSource $source, array $metadata): array
    {
        return [
            ...$source->metadata,
            ...$metadata,
            'provenance' => [
                'source_type' => $source->sourceType,
                'checksum' => $source->contentHash(),
                'mime' => $source->mimeType,
                'size' => $source->size(),
                'ingested_at' => now()->toIso8601String(),
            ],
        ];
    }

    private function assertWithinSizeLimit(IngestionSource $source): void
    {
        $max = (int) $this->config->get('rag-engine.ingestion.max_upload_bytes', 50 * 1024 * 1024);

        if ($max > 0 && $source->size() > $max) {
            throw new RagException("Source exceeds the maximum size of {$max} bytes.");
        }
    }
}
