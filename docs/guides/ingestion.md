---
title: "Ingesting content"
description: "Get content into the engine from text, files, URLs, cloud storage and Eloquent records — with deduplication, versioning and safe deletion."
---

# Ingesting content

**Ingestion** is step one: it takes a source (some text, a file, a URL…) and
registers it as a **`Document`** — stored, encrypted, deduplicated and versioned.
Ingestion does **not** make content searchable on its own; that's the *processing*
step (`Rag::process()`), covered in **[Orchestration](/concepts/orchestration)**.

::: callout info "In plain words"
Ingesting is "filing the document away" — giving it an ID, encrypting it, and
noting where it came from. Making it searchable (chunking + embedding) is a
separate step you run afterwards. Splitting the two lets you ingest quickly now
and process in the background.
:::

## Building and ingesting a source

Use the `SourceFactory` (via `Rag::source()`) to describe a source, then ingest
it:

```php
use Sellinnate\RagEngine\Facades\Rag;

$source   = Rag::source()->text('Some raw text to index.');
$document = Rag::ingest($source, ['tag' => 'note']);   // 2nd arg = extra metadata

$document->id;        // the new Document's id
$document->status;    // 'pending' — not processed yet
```

## The five source types

```php
// 1. Raw text
Rag::source()->text('Refunds take 14 days.', ['document_key' => 'refunds']);

// 2. A local file / upload (parsed by MIME type)
Rag::source()->file('/path/to/report.pdf');

// 3. Cloud storage (any Laravel filesystem disk: S3, R2, local…)
Rag::source()->storage('s3', 'reports/q1.csv');

// 4. A URL (fetched safely — see SSRF note below)
Rag::source()->url('https://example.com/page');

// 5. An Eloquent record (pick which fields to index)
Rag::source()->eloquent($user, ['name', 'bio']);
```

Each returns an `IngestionSource` you pass to `Rag::ingest()`. The third argument
of every factory method is optional metadata merged onto the document.

::: callout tip "Two ways to index Eloquent data"
`Rag::source()->eloquent($model, [...])` is a one-off snapshot of some fields.
For models that should **stay in sync automatically** as they change — and support
recursive composition of relations — use the `HasEmbeddings` trait instead. See
**[Embedding Eloquent models](/concepts/eloquent-models)**.
:::

## Deduplication & idempotency

Ingesting the **same content twice returns the same document** — content is keyed
by a SHA-256 hash. This makes re-running an import safe (*idempotent*): you won't
pile up duplicates.

```php
$a = Rag::ingest(Rag::source()->text('hello'));
$b = Rag::ingest(Rag::source()->text('hello'));

$a->is($b);   // true — only one row exists
```

Two refinements:

- **A source with a `document_key` only matches its own document, and a source
  without one never matches a keyed document.** Two records with identical
  text (e.g. two Eloquent models, or a model and a plain text upload) stay
  separate documents, each with its own metadata.
- **Changed vector metadata is applied, not ignored.** If identical content is
  ingested again with a different `rag_vector_metadata` (see below), the
  existing document takes the new values and goes back to `pending`, so the next
  `Rag::process()` rebuilds its vectors. Without a `document_key`, the last
  writer wins. Give each separately-scoped copy its own `document_key` if you
  need both.

## Versioning

Give a source a stable **logical key** (`document_key`, or a filename/url) and
re-ingesting *changed* content under that key creates a new **version**,
atomically superseding the previous one. This is how you keep "the same document"
up to date over time without duplicates or stale copies.

```php
Rag::ingest(Rag::source()->text('v1 content', ['document_key' => 'policy']));  // version 1
Rag::ingest(Rag::source()->text('v2 content', ['document_key' => 'policy']));  // version 2; v1 superseded
```

::: callout tip "Always set a document_key for updatable content"
Without a key, two edits of the same logical document look like two unrelated
documents. With a key, the engine versions them cleanly and supersedes the old
generation. Use a stable identifier you control (a slug, a filename, a record id).
:::

## Filterable vector metadata {#vector-metadata}

Document metadata (the second argument of every factory method) is stored on the
`Document`. To make metadata **filterable at search time**, put it under the
`rag_vector_metadata` key: every value in it is copied into every vector of the
document.

```php
$document = Rag::ingest(Rag::source()->file($path, [
    'document_key' => 'handbook/leave-policy',
    'rag_vector_metadata' => [
        'scope' => 'hr',             // who may see it
        'year' => 2026,
        'tags' => ['policy', 'leave'],
    ],
]));
Rag::process($document);

Rag::search('parental leave')->where('scope', ['in' => ['hr', 'internal']])->get();
```

- Use **scalars or lists of scalars**. Those are the values every vector store
  can filter on.
- System keys (`tenant_id`, `document_id`, `chunk_id`, `parent_chunk_id`,
  `content`, `is_parent`) are ignored if you set them. The engine always writes
  its own values.
- To change the scope later, ingest the same content again with the new
  `rag_vector_metadata` and process it (see above), or purge and re-ingest it.
- Eloquent models get this automatically from `EmbeddableDefinition::metadata()`
  (see **[Eloquent models](/concepts/eloquent-models#filterable-metadata)**).

## Provenance

Every document records where it came from, under `metadata.provenance`: source
type, content checksum, MIME type, size and an ingestion timestamp. This trail
flows all the way down to each chunk and search result — see
**[Eloquent models → Every chunk is traceable](/concepts/eloquent-models#every-chunk-is-traceable)**.

## Deleting content: soft-delete vs purge

```php
$ingestor = Rag::ingestor();

$ingestor->softDelete($document);  // recoverable; status becomes 'deleted'
$ingestor->purge($document);       // permanent, irreversible erasure
```

- **`softDelete`** hides the document but keeps it recoverable.
- **`purge`** physically removes it *and* crypto-shreds it: because the
  document's wrapped encryption key (DEK) lives only in its row, deleting the row
  makes the encrypted content permanently unrecoverable — **even from database
  backups** — while the tenant's master key is still alive. The document's
  vectors, chunks and embedding bookkeeping rows are deleted too, so
  `rag:reconcile` stays consistent.

::: callout warning "Purge is irreversible"
`purge()` is your "right to erasure" tool: it cannot be undone, and recovery from
backups is cryptographically prevented. Use `softDelete()` if you might need the
content back. See **[Security & BYOK](/concepts/security)**.
:::

## URL ingestion is SSRF-guarded

**SSRF (Server-Side Request Forgery)** is an attack where a server is tricked into
fetching internal addresses (cloud metadata endpoints, `localhost`, internal
services). The `url()` source is hardened against it:

::: callout warning "What url() refuses"
- Non-`http(s)` schemes (no `file://`, `gopher://`, …).
- Any host that resolves to a private, loopback, link-local or reserved IP
  (cloud metadata, localhost, internal networks).
- Redirects are **not** followed (so a public URL can't bounce to an internal one).
:::

## Best practices

- **Set a `document_key`** for anything that can change, so updates version
  cleanly.
- **Process after ingesting** (inline or via `ProcessDocumentJob`) — ingestion
  alone isn't searchable.
- **Validate uploads** (size, type) at your app boundary before ingesting
  untrusted files.
- **Use `purge()` for erasure requests**, `softDelete()` when content might come
  back.
- **For DB-backed content, prefer the `HasEmbeddings` trait** over one-off
  `eloquent()` snapshots, so the index self-updates.

## Next

- **[Orchestration & jobs](/concepts/orchestration)** — process documents at scale.
- **[Chunking](/concepts/chunking)** and **[Embedding](/concepts/embedding)** —
  what processing does.
