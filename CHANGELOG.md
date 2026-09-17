# Changelog

All notable changes to `:package_name` will be documented in this file.

## v1.3.0 — access-scoped corpora, database KMS store, image OCR - 2026-09-17

Access-scoped corpora: filterable model metadata, no plaintext in the vector store, SQL filters on pgvector, a multi-node KMS key store and image OCR.

### New

- **Filterable model metadata.** Scalar and list values passed to `EmbeddableDefinition::metadata()` are now copied into every vector. You can filter on them, e.g. `Rag::search($q)->where('scope', ['in' => $allowed])`. Engine keys (`tenant_id`, `document_id`, `chunk_id`, `parent_chunk_id`, `content`, `is_parent`, `embeddable_*`) always win and can't be overridden. Non-Eloquent sources use `rag_vector_metadata`.
- **Changing only the metadata re-indexes.** If a model's scope changes but its text doesn't, its vectors are rebuilt. Two models with identical text are now two separate documents.
- **pgvector filters run in SQL.** Every filter operator is compiled to parameterised `jsonb` SQL and applied before `ORDER BY … LIMIT`, so a selective filter still returns `topK` hits. Filtered queries use iterative index scans on pgvector ≥ 0.8 and an exact scan on older versions.
- **List-valued metadata** is filtered element by element on every store: a document tagged `['hr','finance']` matches `where('tags', ['in' => ['finance']])`.
- **`database` key store for the local KMS** (`RAG_KMS_STORE=database`, optional `RAG_KMS_CONNECTION`, new `rag_kms_keys` table). Built for multi-node and ephemeral-disk hosting such as Laravel Cloud. Keys are always encrypted with `RAG_KMS_MASTER_KEY` (≥ 32 chars; the store refuses to start without it). Destroying a key hard-deletes its row, and key creation is atomic across nodes.
- **Images through OCR.** PNG/JPEG/WebP/TIFF uploads and `addFile()` fields are sent to the configured OCR engine. Register your own engine (e.g. a vision LLM) with `OcrManager::extend()`.
- **`rag:reindex {tenant}`** rebuilds a tenant's vectors from its stored documents. **`rag:reconcile {tenant} --prune`** deletes orphan embedding records.
- **`RAG_AUDIT_DB_TRIGGERS=false`** skips the audit-log WORM triggers on hosts that reject `CREATE TRIGGER`. The model-level guard stays on.

### Behaviour changes

- **No plaintext in the vector store by default.** New setting `security.vector_payload_content` (`RAG_VECTOR_PAYLOAD_CONTENT`). When unset and encryption is on, chunk text is no longer written to the vector store (payload / pgvector `content` column); search decrypts hit text from `rag_chunks` instead. This is the secure default: crypto-shredding now covers the text everywhere. Set it to `true` for the old behaviour. **Vectors indexed before this release still contain plaintext until you run `rag:reindex`.**
- **Range filters** (`gt`/`gte`/`lt`/`lte`) only compare numbers with numbers and strings with strings. A missing key no longer matches `lt`. `null` filters also match empty lists.
- **Local KMS config fails closed:** an unknown `kms.local.store`, or `file` without a `keystore`, now throws.
- **Dedup is scoped by `document_key`:** keyed sources only match their own document, and key-less sources never match keyed documents.
- **Qdrant** now supports `eq`/`neq`/`in`/`nin`/`null` filters. An empty `in` list returns no results. `in`/`nin` with a non-list value now throws on every store. String ranges throw (Qdrant ranges are numeric only).

### Fixes

- Purging a document (and so every superseded or forgotten model) now also deletes its `rag_embeddings` rows, so `rag:reconcile` no longer reports orphans.
- Models with a custom `documentKey()` are now removed on delete, in queued forgets, and by `rag:reindex`.
- Concurrent keyed ingests of identical content are retried instead of failing.
- On MySQL/MariaDB (`REPEATABLE READ`), nodes see KEKs that another node has just created, and KEK rotation is atomic.
- pgvector searches inside a caller's transaction restore its index-scan settings.
- The migration's `down()` also drops the `rag_vectors` tables.

### Upgrading from v1.2

1. `composer update sellinnate/rag-engine`
2. `php artisan vendor:publish --tag="rag-engine-migrations"`. Only the new `create_rag_kms_keys_table` migration is copied; no existing table gets new columns or indexes.
3. `php artisan migrate`
4. Optional: copy the new config keys (`security.vector_payload_content`, `audit`, `kms.local.connection`, `tables.kms_keys`). The defaults apply without them.
5. For each tenant, run `php artisan rag:reindex {tenant}` (strips legacy plaintext and adds filterable metadata), then `php artisan rag:reconcile {tenant} --prune`.

### Quality

521 tests plus 14 pgvector integration tests (run against real Postgres, and in CI on pgvector ≥ 0.8). PHPStan level 8, Pint, coverage 93.7%, green across Linux and Windows × PHP 8.3/8.4/8.5 × Laravel 12/13. Docs: see Security, Retrieval, Vector stores, Eloquent models, Parsing and Installation → Upgrading.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

## v1.2.0 — retries, AWS KMS, evaluation harness, PDF OCR - 2026-06-27

Production-readiness features from the package review. Backward compatible.

### New

- **Resilient providers** — LLM and reranker HTTP calls now retry transient failures (429/5xx/connection) with exponential backoff + jitter, failing fast on 4xx. Tune with `retries` / `max_attempts`.
- **AWS KMS driver** (`RAG_KMS=aws`) — production BYOK with **one CMK per tenant** (alias-based), so crypto-shredding one tenant never affects another. Needs `aws/aws-sdk-php`.
- **RAG evaluation harness** — measure **recall@k, precision@k, hit-rate and MRR** over a labelled dataset, in code (`Evaluator`) or via **`php artisan rag:evaluate dataset.json`**. Tune chunking/embedders/retrieval with numbers.
- **OCR for scanned PDFs** (`RAG_OCR=tesseract`) — when a PDF has no text layer, the parser falls back to OCR. Pluggable `Ocr` contract; off by default.

### Repo

- Added `SECURITY.md` (responsible-disclosure policy) and `CONTRIBUTING.md`.

### Docs

New **[Evaluating quality](https://laravel-rag-engine.selli.io/guides/evaluation)** guide; retry, OCR (parsing) and AWS KMS (security) sections; README, `.env.example` and config updated.

### Quality

429 tests (incl. retry, AWS KMS via mock handler, evaluation, OCR fallback) + a native-pgvector CI integration job, PHPStan level 8, Pint, coverage ≥90%, green across Linux + Windows × PHP 8.3/8.4/8.5 × Laravel 12/13.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

## v1.1.1 — fix PHPStan CI (console input typing) - 2026-06-27

Patch release. No runtime or behaviour change.

### Fix

- The standalone **PHPStan** CI workflow (PHP 8.5 + latest larastan) failed on console commands casting `mixed` `argument()`/`option()` values to `string` — an unsound cast that older local larastan didn't flag (the run-tests matrix was always green; PHPStan had been red since v1.0.0).
- Added `Console\Concerns\NormalizesInput` (`stringArgument(): string`, `stringOption(): ?string`) and used it across all six Artisan commands. No more `mixed → string` casts.

Verified against the same larastan version CI installs: PHPStan level 8 clean. Suite 410 green; **both** run-tests and PHPStan CI green.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

## v1.1.0 — embeddable file fields (PDF/DOCX) + binary handling - 2026-06-27

### What's new

Embeddable Eloquent models can now embed **file fields** (an uploaded PDF, DOCX, …), and non-embeddable binaries are handled safely.

- **`EmbeddableDefinition::addFile($label, $path, $disk = null, $mime = null)`** — fold an uploaded file's text into the model's embedding. The engine reads the file (from a Laravel disk or a local path), parses it with the built-in parsers (PDF, DOCX, HTML, CSV, JSON, XML, Markdown, text), and the file becomes searchable **as part of the model** — a search hit still resolves back to the model.
- **Robust binary handling** — a `.zip`, executable, image, missing/empty/oversized or unparsable file can't be embedded, and raw binary is **never** sent to an embedding provider. Behaviour is governed by `rag-engine.eloquent.on_unparsable_file`:
  - `skip` (default) — log a warning and embed the rest of the model.
  - `fail` — throw `Sellinnate\RagEngine\Exceptions\UnsupportedFileException`.
  - Size guard via `rag-engine.eloquent.max_file_bytes`.
  

```php
public function toEmbeddable(): EmbeddableDefinition
{
    return EmbeddableDefinition::make()
        ->add('Title', $this->title)
        ->addFile('Document', $this->document_path, 's3'); // PDF/DOCX on a disk
}




```
> PDF support uses the optional `smalot/pdfparser` package (`composer require smalot/pdfparser`). Other formats need nothing extra.

### Compatibility

Fully backward compatible. PHP 8.2+ · Laravel 11/12/13.

### Quality

410 tests (incl. PDF on local path & Laravel disk, zip/exe skip & fail, missing/oversized file) + native-pgvector CI integration job, PHPStan level 8, Pint, coverage ≥90%, green across Linux + Windows × PHP 8.3/8.4/8.5 × Laravel 12/13.

📖 Docs: https://laravel-rag-engine.selli.io/concepts/eloquent-models#file-fields

🤖 Generated with [Claude Code](https://claude.com/claude-code)

## v1.0.0 — first stable release - 2026-06-27

First stable release of **RAG Engine for Laravel** — semantic search and AI answers over your own content, behind stable contracts.

### Highlights

- **Ingestion** from text, files, URLs (SSRF-guarded), cloud storage and Eloquent records; safe parsing of Markdown/HTML/XML/CSV/JSON/DOCX/PDF.
- **Preprocessing & PII redaction** (mask/tokenize) on by default.
- **Chunking**: recursive/sentence/markdown/fixed, token-aware, parent-child, contextual headers.
- **Embedding — 10 providers**: OpenAI, Azure OpenAI, Mistral, Jina, Voyage, Cohere, Gemini, Hugging Face, Ollama, + deterministic `fake`.
- **Vector stores**: `memory`, portable `database` (SQL), **native `pgvector`** (vector column + HNSW + `<=>`), `qdrant` (incl. quantization).
- **Retrieval**: metadata filters, hybrid (BM25 + RRF), MMR, **cross-encoder reranking** (Cohere/Jina), thresholds, multi-query expansion, small-to-big parent expansion, token budgeting.
- **Generation**: Anthropic (Claude) + OpenAI-compatible LLMs, cited answers, **streaming** via `Rag::ask()->stream()`.
- **Embeddable Eloquent models** via a contract — recursive relation composition, auto-sync, vector→model trace-back.
- **Security**: BYOK envelope encryption, KMS abstraction, crypto-shredding, key rotation; tamper-evident WORM audit log.
- **Multi-tenancy**: fail-closed per-tenant scoping. **Operations**: cost tracking, lifecycle events, queued/batchable jobs, Artisan commands.

### Requirements

PHP 8.2+ · Laravel 11, 12 or 13.

### Install

```bash
composer require sellinnate/rag-engine





```
### Quality

401 tests + a native-pgvector CI integration job (real Postgres), PHPStan level 8, Pint, coverage ≥90%, green across Linux + Windows × PHP 8.3/8.4/8.5 × Laravel 12/13.

📖 Docs: https://laravel-rag-engine.selli.io

🤖 Generated with [Claude Code](https://claude.com/claude-code)
