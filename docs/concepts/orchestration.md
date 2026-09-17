---
title: "Orchestration & jobs"
description: "Run indexing asynchronously and reliably: the pipeline state machine, queued jobs, batching, events, Artisan commands and quotas."
---

# Orchestration & jobs

Indexing is the slow half of RAG — it calls embedding models, which take time and
can fail transiently. **Orchestration** is how the engine runs that work
reliably: as a state-tracked, retryable, queueable pipeline.

::: callout info "In plain words"
Turning a document into searchable vectors can take seconds and occasionally
fails (network blip, rate limit). You don't want that blocking a web request or
losing data on a hiccup. So indexing runs as a background **job** that tracks its
progress and retries on failure.
:::

## The pipeline as a state machine

Each document moves through clear states, so you always know where it is:

```
pending → parsing → chunking → embedding → indexed
                                        ↘ failed
```

If anything throws, the document lands in `failed` (never half-indexed), and an
event is emitted so you can alert or retry.

## Processing a document

**Inline** (simple; blocks until done — fine for scripts and small jobs):

```php
use Sellinnate\RagEngine\Facades\Rag;

$document = Rag::ingest(Rag::source()->file('handbook.pdf'));
Rag::process($document);   // runs the whole pipeline now
```

**Queued** (recommended in production; returns immediately, runs on a worker):

```php
use Sellinnate\RagEngine\Pipeline\ProcessDocumentJob;

$document = Rag::ingest(Rag::source()->file('handbook.pdf'));
ProcessDocumentJob::dispatch($document->id, $document->tenant_id);
```

::: callout tip "Inline vs queued"
Use **inline** in tinker, tests and one-off scripts. Use **queued** anywhere a
user is waiting (HTTP requests) or when importing many documents — so requests
stay fast and failures retry automatically. You'll need a running queue worker
(`php artisan queue:work`).
:::

## Indexing a whole corpus

`ProcessDocumentJob` is **batchable**, so you can import thousands of documents as
one tracked batch:

```php
use Sellinnate\RagEngine\Pipeline\ProcessDocumentJob;
use Illuminate\Support\Facades\Bus;

$batch = Bus::batch(
    $documents->map(fn ($d) => new ProcessDocumentJob($d->id, $d->tenant_id))
)->name('corpus-import')->dispatch();

$batch->id;   // track progress via Laravel's batch API
```

Jobs **retry with backoff** on transient errors. A terminal failure marks the
document `failed` and emits `IngestionFailed` (a dead-letter signal you can
monitor).

## Lifecycle events

The engine emits events at every meaningful step. Listen to them to build
dashboards, send notifications, or trigger follow-up work:

| Event | Fires when… |
|---|---|
| `DocumentIngested` | A source was registered as a Document. |
| `DocumentChunked` | A document was split into chunks. |
| `ChunksEmbedded` | Chunks were embedded. |
| `DocumentIndexed` | A document finished indexing (now searchable). |
| `SearchPerformed` | A search ran (useful for analytics). |
| `IngestionFailed` | Processing failed terminally (dead-letter). |
| `KeyRotated` | A tenant's encryption key was rotated. |
| `DataShredded` | A tenant/document was crypto-shredded. |

```php
use Illuminate\Support\Facades\Event;
use Sellinnate\RagEngine\Events\DocumentIndexed;

Event::listen(function (DocumentIndexed $e) {
    Log::info("Indexed document {$e->documentId} for tenant {$e->tenantId}");
});
```

## Artisan commands

Operational commands ship with the package:

| Command | Purpose |
|---|---|
| `rag:status` | Document counts by pipeline state (how much is pending/failed). |
| `rag:stats {tenant}` | Token/cost usage and quota consumption for a tenant. |
| `rag:reconcile {tenant}` | Report inconsistencies between chunks and stored vectors. |
| `rag:reindex {tenant}` | Rebuild a tenant's vectors from its stored documents, in their current namespaces. Eloquent documents are re-synced from the live model. Run it after upgrades that change what vectors hold (see [Security](/concepts/security#vector-payload-content)). Exits non-zero if any document failed. |
| `rag:rotate-keys {tenant}` | Rotate a tenant's key-encryption key and re-wrap its data keys. |
| `rag:purge {tenant}` | Crypto-shred a tenant (irreversible erasure). |
| `rag:clear-cache` | Clear cached embeddings. |

```bash
php artisan rag:status
php artisan rag:stats acme-corp
```

## Quotas {#quotas}

Per-tenant limits keep cost and abuse in check. Set them in config (max documents,
corpus size, embedding tokens); they're enforced **during ingestion** and throw
`QuotaExceededException` when breached:

```php
use Sellinnate\RagEngine\Exceptions\QuotaExceededException;

try {
    Rag::ingest($source);
} catch (QuotaExceededException $e) {
    // tell the tenant they've hit their plan limit
}
```

See **[Configuration → Multi-tenancy](/getting-started/configuration#multi-tenancy)**.

## Best practices

- **Queue indexing in production**; keep a worker running and monitored.
- **Use batches for bulk imports** so you can track and resume.
- **Listen to `IngestionFailed`** and alert on it — that's your dead-letter queue.
- **Run `rag:reconcile` periodically** to catch chunk/vector drift.
- **Set quotas** for every tenant to cap runaway embedding cost.

## Common pitfalls

::: callout warning
- **Dispatched a job but nothing happens?** No queue worker is running, or the
  queue connection is `sync` in `.env`.
- **Documents stuck in `pending`?** They were ingested but never processed — call
  `Rag::process()` or dispatch `ProcessDocumentJob`.
- **Many `failed` documents?** Check the embedder config/credentials and watch
  `IngestionFailed` for the reason.
:::

## Next

- **[Multi-tenancy](/concepts/multi-tenancy)** — isolating tenants.
- **[Security & BYOK](/concepts/security)** — erasure and key rotation.
