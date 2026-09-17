# Technical Notes — Cycle 7: integration gaps for access-scoped corpora (v1.3.0)

Context: integrating the package as an admin-only "company brain" (MySQL for the
package tables, a separate Postgres + pgvector connection for vectors, Laravel
Cloud hosting) with access control done by the host app through a metadata key
`scope` on every vector plus `where('scope', ['in' => [...]])`. A source-code
spike found seven gaps. Each was reproduced with a failing test before the fix.

## A — Declared Eloquent metadata never reached the vectors

`ModelEmbedder::sync()` only put the `embeddable_*` identity into
`rag_vector_metadata`, so `EmbeddableDefinition::metadata()` values were stored
on the document but could not be filtered on.

Fix: `ModelEmbedder::filterableMetadata()` keeps scalar and list-of-scalar
values and merges them into `rag_vector_metadata`, with the identity keys
applied last. `Indexer` now builds the payload in the order provenance → chunk
metadata → propagated metadata → system keys, and strips
`Indexer::SYSTEM_KEYS` from the propagated metadata. An explicit caller
`rag_vector_metadata` on text/file/storage sources keeps working, with the same
guard.

## B — Metadata-only changes never re-indexed

`Ingestor::ingest()` deduplicated on the content hash alone, and
`ModelEmbedder::sync()` only re-processed new or non-indexed documents. A scope
change without a text change therefore left stale scopes on the vectors (an
access-control hole). Dedup also matched *across* models, so two models with
identical text shared one document.

Fix: a source with an explicit `document_key` now only dedupes against its own
logical document. On a duplicate, `Ingestor::refreshDuplicate()` applies the
incoming metadata (keyed sources) or an explicitly supplied, different
`rag_vector_metadata` (un-keyed sources, last writer wins), and flags the
document `pending` when the propagated metadata changed, so callers re-process
it. `Indexer` reads the document metadata fresh from the database inside its
per-document lock, so a stale in-memory copy can't overwrite a newer scope.
`ModelEmbedder::sync()` also accepts `force`.

## C — Chunk plaintext in the vector store despite encryption

`Indexer::buildRecords()` always copied `content` into the payload (and the
pgvector `content` column).

Fix: new `security.vector_payload_content` (`RAG_VECTOR_PAYLOAD_CONTENT`),
where `null` (auto) means only when encryption is off, and a typo throws.
`Retriever::hydrate()` fills empty hit content right after each store search,
before hybrid scoring, rerank, dedup, MMR, parent expansion and budgets. It does
this with one tenant-scoped `rag_chunks` query and decrypts each row. Hits
without a chunk row are dropped. Legacy hits with payload text are used as-is.
Upgrade path: the new `rag:reindex {tenant}` rebuilds every current document in
its own namespace (Eloquent documents re-synced from the live model with
`force`; documents whose model is gone are removed).

## D — pgvector filtered in PHP after `LIMIT topK*5`

Fix: `PgVectorFilterCompiler` turns the `MetadataMatcher` grammar into a
parameterised, null-safe SQL fragment on the `jsonb` column: `-> ?::text`
field access, `?::jsonb` literals, `COALESCE(…, FALSE)` so `neq`/`nin` are
plain negations, and typed `CASE` ranges (numeric, or `COLLATE "C"` strings).
Keys are bound and validated against `KEY_PATTERN`, and the SQL text is typed
`literal-string`. `PgVectorStore::search()` adds the fragment before
`ORDER BY … LIMIT` and runs it in a transaction with `SET LOCAL`:
`hnsw.ef_search` = clamp(limit, 40, 1000); for filtered queries, iterative
scans on pgvector ≥ 0.8, or `enable_indexscan = off` (an exact scan) on older
versions, because post-filtering an HNSW scan capped at `ef_search` under-fills.
`deleteByFilter()` uses the same fragment. `MetadataMatcher` range operators
now only compare numbers with numbers and strings with strings (byte-wise),
matching SQL and Qdrant; previously PHP's loose comparison made a missing key
match `lt`.

Verified against Postgres 16 + pgvector 0.6.1 locally (gated
`RAG_PGVECTOR_TEST`), including a cross-check of every operator with the
in-memory matcher; CI's `pgvector/pgvector:pg16` image covers the ≥ 0.8
iterative path.

## E — No multi-node key store for the local KMS

Fix: `DatabaseKeyStore` (`kms.local.store = database`, optional
`kms.local.connection`, table `tables.kms_keys` from the new
`create_rag_kms_keys_table` migration). KEK material is always sealed with the
master key (at least 32 characters, otherwise it fails closed) inside an
envelope bound to the SHA-256 key id. `forget()` hard-deletes. The new
`AtomicKeyStore::add()` is an insert-if-absent that `LocalKms::createKey()`
prefers, so concurrent nodes can't overwrite a fresh KEK. `KmsManager` now
throws on an unknown store or a `file` store without a directory.

## F — Audit triggers on restricted MySQL hosts

Fix: `audit.db_triggers` (`RAG_AUDIT_DB_TRIGGERS`, default true) gates trigger
creation in the migration; the `AuditEntry` model guard is unchanged. The
migration's `down()` now also drops `rag_vectors` / `rag_vector_namespaces`.

## H — Orphan embedding records after purge

`Ingestor::purge()` deleted a document's chunks but not their
`rag_embeddings` rows, and there is no foreign key between the two tables.
Every superseded Eloquent generation or forgotten model therefore left orphans
that `rag:reconcile` reported. Fix: `purge()` deletes the embedding records of
the purged chunks (tenant-scoped, in batches of 500) in the same transaction.
The Indexer's re-index swap is now tenant-scoped and batched too. The
crypto-shred path already deleted by tenant. `rag:reconcile --prune`
(`Reconciler::pruneOrphans()`) cleans up orphans left by earlier versions.

## Review follow-ups

- **Keyed insert races (Bugbot).** Two documents with the same bytes under
  different keys share the `(tenant_id, content_hash, version)` unique index. A
  keyed insert that loses that race without a winner under its own key is now
  retried (up to 3 attempts) with a freshly computed version.
- **System keys in chunk metadata (Bugbot).** `Indexer::payloadMetadata()`
  now also strips `SYSTEM_KEYS`, so a parser-provided `content` can't put
  plaintext into the payload.
- **Custom document keys on forget (Bugbot).** `ModelEmbedder::forget()` and
  `forgetByIdentity()` (used by the queued job and `rag:reindex`) now also match
  the stored `embeddable_type`/`embeddable_id`, so models with a custom
  `documentKey()` are removed as well.

## G — Images never reached OCR

No parser claimed `image/*`, so images failed as unsupported even with OCR
configured. Fix: `ImageParser` claims PNG/JPEG/WebP/TIFF (plus aliases) only when
the OCR engine `supports()` the type. With the `null` engine the previous
behaviour is unchanged, and empty OCR output is a `ParsingException`. Image
extensions were added to the MIME maps of `SourceFactory` and
`EmbeddableFileResolver`, and WebP to `TesseractOcr`. `OcrManager::extend()`
(inherited from `DriverManager`) is covered by a test that registers a custom
vision driver and indexes an image end to end.
