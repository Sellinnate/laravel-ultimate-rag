<p align="center">
  <img src="art/banner.png" alt="RAG Engine for Laravel — semantic search &amp; AI answers" width="100%">
</p>

# RAG Engine for Laravel

[![Tests](https://img.shields.io/badge/tests-515%20passing-brightgreen)]()
[![Coverage](https://img.shields.io/badge/coverage-%E2%89%A590%25-brightgreen)]()
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-blue)]()
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4)]()
[![Laravel](https://img.shields.io/badge/Laravel-11%20|%2012%20|%2013-ff2d20)]()
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)
[![Docs](https://img.shields.io/badge/docs-laravel--rag--engine.selli.io-2563eb)](https://laravel-rag-engine.selli.io)

📖 **Full documentation: [laravel-rag-engine.selli.io](https://laravel-rag-engine.selli.io)**

Add **semantic search and AI answers over your own content** to any Laravel app.
RAG Engine owns the whole Retrieval-Augmented Generation pipeline — ingesting
documents, splitting them, turning them into searchable vectors, and retrieving
the most relevant passages for any query. Writing a final answer with an LLM is
an optional layer on top.

> **Infrastructure, not a feature.** The engine owns *ingestion → retrieval*;
> generation is optional and decoupled. Vertical packages, internal agents and
> search modules build on top without re-implementing ingestion, chunking,
> embedding or retrieval.

### What you can build

- 🔎 **Semantic search** — a search box that matches by *meaning*, not keywords.
- 🤖 **AI Q&A / chatbots** — LLM answers grounded in *your* content, with citations.
- 📚 **"Ask your docs / tickets / wiki"** features inside an existing app.
- 🧭 **Similarity / recommendations** — "find records like this one".

Use just the search half (no LLM, no AI bill) or add generation later — same
code, one config switch.

## Table of contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Indexing Eloquent models](#indexing-eloquent-models)
- [Asking questions with an LLM](#asking-questions-with-an-llm)
- [Configuration](#configuration)
- [Supported drivers](#supported-drivers)
- [Security & multi-tenancy](#security--multi-tenancy)
- [Documentation](#documentation)
- [Testing & development](#testing--development)
- [License](#license)

## Features

- **Multi-format ingestion** — raw text, file uploads, URLs (SSRF-guarded), cloud
  storage and Eloquent records. Safely parses Markdown, HTML, XML, CSV, JSON,
  DOCX and PDF, and images (PNG/JPEG/WebP/TIFF) through OCR.
- **Pluggable everything** — parsing, chunking, embedding, vector store,
  reranking and LLM are swappable drivers behind stable contracts.
- **10 embedding providers** — OpenAI, Azure OpenAI, Mistral, Jina, Voyage,
  Cohere, Gemini, Hugging Face, Ollama, plus a deterministic `fake` driver for
  tests.
- **Powerful retrieval** — metadata filters (pushed into SQL on pgvector, usable
  for access scopes), hybrid (semantic + keyword) search
  with RRF, MMR diversification, reranking, relevance thresholds and
  small-to-big (parent-child) context expansion.
- **Embeddable Eloquent models** — make any model searchable via one contract;
  recursive composition of relations, auto-sync on change (including
  metadata-only changes), filterable metadata on every vector, and vector→model
  trace-back.
- **Security by design** — BYOK envelope encryption with a KMS abstraction
  (`local` with an in-memory, file or multi-node **database** key store, plus
  **AWS KMS**), no plaintext chunk text in the vector store when encryption is on,
  crypto-shredding for "right to erasure", and PII redaction on by default.
- **OCR for scanned PDFs and images** — pluggable OCR (Tesseract, or your own
  engine via `OcrManager::extend()`) reads scans and image uploads.
- **Quality evaluation** — measure recall@k, precision@k, hit-rate and MRR over a
  labelled dataset (`rag:evaluate`).
- **Resilient providers** — LLM, reranker and embedder HTTP calls retry transient
  failures with exponential backoff.
- **Multi-tenancy** — automatic, fail-closed per-tenant scoping of every query.
- **Operations from day one** — immutable (WORM) audit log, cost tracking,
  lifecycle events, queued/batchable ingestion and Artisan commands.
- **EU-resident by default** — content and embeddings stay in the EU unless you
  explicitly opt into a non-EU provider.

## Requirements

| Requirement | Version |
|---|---|
| PHP | **8.2+** |
| Laravel | **11, 12 or 13** |
| A database | any Laravel-supported (SQLite is fine to start) |

A dedicated vector database is **not** required to begin: the default store is
in-memory, and the `database` store works on plain Postgres/MySQL/SQLite. Use
native `pgvector` or Qdrant at larger scale.

## Installation

```bash
composer require sellinnate/rag-engine

php artisan vendor:publish --tag="rag-engine-config"
php artisan vendor:publish --tag="rag-engine-migrations"
php artisan migrate
```

The service provider and `Rag` facade auto-register via package discovery. Out of
the box the package uses zero-network, deterministic drivers (`fake` embedder,
in-memory store, local KMS) so your test suite runs offline.

> [!IMPORTANT]
> The `fake` embedder is for **tests only** — it doesn't understand meaning. For
> a real search feature, configure a real embedder (see
> [Configuration](#configuration)).

## Quick start

```php
use Sellinnate\RagEngine\Facades\Rag;

// 1. INGEST — register content as a Document (stored & encrypted, not yet searchable).
$document = Rag::ingest(
    Rag::source()->text('Refunds are issued within 14 business days of an approved request.')
);

// 2. PROCESS — run the pipeline: parse → clean & redact PII → chunk → embed → store.
Rag::process($document);            // or: ProcessDocumentJob::dispatch(...) on a queue

// 3. SEARCH — find the most relevant chunks by meaning.
$hits = Rag::search('how long until I get my money back?')->topK(3)->get();

$hits[0]->content;                  // "Refunds are issued within 14 business days..."
$hits[0]->score;                    // relevance score
$hits[0]->metadata['source_ref'];   // provenance: where it came from

// 4. (OPTIONAL) ASK — let an LLM write a cited answer from the retrieved chunks.
$answer = Rag::ask('how long do refunds take?')->using('openai')->generate();
$answer->answer;                    // "Refunds take 14 business days. [1]"
$answer->citations;                 // [['index' => 1, 'document_id' => '…', 'chunk_id' => '…']]
```

Refine retrieval fluently:

```php
$hits = Rag::search('envelope encryption')
    ->topK(5)
    ->threshold(0.4)        // drop weak matches
    ->where('scope', ['in' => ['docs', 'internal']])  // metadata filter
    ->hybrid()              // semantic + keyword (RRF)
    ->rerank()              // precision pass
    ->expandParents()       // small-to-big context
    ->get();
```

## Indexing Eloquent models

If the content you want to search already lives in your database, make the model
embeddable — it then stays in sync automatically as rows change, and every vector
traces back to its model.

```php
use Sellinnate\RagEngine\Concerns\HasEmbeddings;
use Sellinnate\RagEngine\Contracts\Embeddable;
use Sellinnate\RagEngine\Eloquent\EmbeddableDefinition;

class Article extends Model implements Embeddable
{
    use HasEmbeddings; // auto-indexes on save, removes on delete

    public function toEmbeddable(): EmbeddableDefinition
    {
        return EmbeddableDefinition::make()
            ->add('Title', $this->title)
            ->add('Body', $this->body)
            ->include($this->author, 'author')          // compose a related model
            ->includeMany($this->comments, 'comments');  // recursively
    }
}

// Trace a search hit back to its model:
$article = Rag::models()->resolve($hits[0]); // App\Models\Article instance, or null
```

Model **file fields** (a PDF/DOCX upload) can be embedded too — `addFile()` parses
the file to text and folds it into the model's embedding; non-embeddable binaries
(zip/exe…) are skipped or rejected per policy. See
**[docs/concepts/eloquent-models.md](docs/concepts/eloquent-models.md)**.

## Asking questions with an LLM

Search returns the relevant chunks; an **LLM** turns them into a written, cited
answer via `Rag::ask()`. This layer is optional and decoupled — with the default
`null` driver, `ask()` returns the sources with an empty answer, so search-only
apps carry no LLM dependency.

The package ships two LLM drivers: **`anthropic`** (Claude) and **`openai`**
(OpenAI and any OpenAI-compatible API — Mistral, Ollama, Groq, OpenRouter…).

```dotenv
# .env — use Anthropic Claude to answer questions
RAG_LLM=anthropic
RAG_ANTHROPIC_API_KEY=sk-ant-...
RAG_ANTHROPIC_MODEL=claude-sonnet-4-6     # or claude-opus-4-8 / claude-haiku-4-5-...
```

```php
$result = Rag::ask('What is our refund policy?')
    ->topK(5)
    ->using('anthropic')   // or omit to use the default RAG_LLM
    ->generate();

$result->answer;     // "Refunds are issued within 14 business days. [1]"
$result->citations;  // [['index' => 1, 'document_id' => '…', 'chunk_id' => '…']]
$result->sources;    // the SearchHits the answer was built from
```

> [!NOTE]
> **Anthropic has no embedding API**, so `anthropic` is a *generation-only*
> driver. Keep a real `RAG_EMBEDDER` (Mistral, OpenAI, Ollama…) for the search
> side. A common combo is **Mistral/Ollama embeddings + Claude answers**.
>
> Retrieved content is treated as untrusted: the default prompt fences it and
> tells the model not to follow instructions inside it (prompt-injection
> hardening). Full guide:
> **[docs/concepts/generation.md](docs/concepts/generation.md)**.

## Configuration

Configuration lives in `config/rag-engine.php` and works like Laravel's
`config/database.php`: you define named *connections* per subsystem and pick a
`default`. Switching provider = changing one name in `.env`.

```dotenv
# .env — switch to a real embedder (Ollama is free & local)
RAG_EMBEDDER=ollama
RAG_OLLAMA_BASE_URL=http://localhost:11434

# ...or a hosted provider:
RAG_EMBEDDER=openai
RAG_OPENAI_API_KEY=sk-...
```

> [!NOTE]
> **API keys go in `.env`, never in the committed config.** A copy-ready list of
> every variable ships as [`.env.example`](.env.example). See
> [docs/getting-started/configuration.md](docs/getting-started/configuration.md).

## Supported drivers

**Embedders** (`RAG_EMBEDDER`)

| Driver | Provider | Residency |
|---|---|---|
| `openai` | OpenAI | global |
| `azure-openai` | Azure OpenAI | EU (EU region) |
| `mistral` | Mistral | EU |
| `jina` | Jina AI | EU |
| `voyage` | Voyage AI | global |
| `cohere` | Cohere | global |
| `gemini` | Google Gemini | global |
| `huggingface` | Hugging Face / self-hosted TEI | global / self-host |
| `ollama` | Ollama (BGE/E5/Nomic) | self-hosted |
| `fake` | deterministic (tests) | local |

**Vector stores** (`RAG_VECTOR_STORE`): `memory` (tests/dev) · `database`
(portable SQL: Postgres/MySQL/SQLite, brute-force) · `pgvector` (**native**
Postgres ANN: `vector` column + HNSW + `<=>`) · `qdrant` (EU self-hostable, ANN at
scale). Full setup, including **where to configure the Postgres connection**, is
in the [Vector stores guide](https://laravel-rag-engine.selli.io/concepts/vector-stores).

**LLMs** (`RAG_LLM`, for `ask()`): `anthropic` (Claude) · `openai` (OpenAI and
any OpenAI-compatible API: Mistral, Ollama, Groq, OpenRouter…) · `null`/`fake`.
Anthropic is generation-only (no embeddings). Answers can be **streamed** with
`Rag::ask(...)->stream()`.

**Rerankers** (`RAG_RERANKER`, optional cross-encoder pass): `cohere` · `jina`
(EU) · `null`/`fake`.

**KMS** (`RAG_KMS`, BYOK key management): `local` (key store
`RAG_KMS_STORE=array|file|database`; `database` for multi-node hosting) · `aws`
(AWS KMS, production).

**OCR** (`RAG_OCR`, scanned PDFs and images): `null` · `tesseract` · your own via
`OcrManager::extend()`.

**Parsers**: plain text · Markdown · HTML · XML · CSV/TSV · JSON · DOCX · PDF
(+ OCR for scans) · images PNG/JPEG/WebP/TIFF (via OCR).

**Chunkers**: `recursive` (default) · `sentence` · `markdown` · `fixed`
(char- or token-based), with optional parent-child and contextual headers.

All drivers share one contract — switching backends needs no code changes, and
you can register your own (see
[docs/guides/custom-drivers.md](docs/guides/custom-drivers.md)).

## Security & multi-tenancy

- **BYOK envelope encryption** — content is encrypted at rest with per-item DEKs
  wrapped by a tenant KEK in a KMS; the plaintext key never persists. With
  encryption on, chunk text is **not** copied into the vector store
  (`RAG_VECTOR_PAYLOAD_CONTENT`); search decrypts it from the database instead.
- **Crypto-shredding** — honour "right to erasure" by destroying the key, making
  data unrecoverable everywhere (including DB backups) at once.
- **PII redaction** — emails, cards (Luhn), IBANs (mod-97), Italian fiscal codes
  and phone numbers are redacted before indexing, by default.
- **Fail-closed multi-tenancy** — every query is automatically scoped to the
  current tenant; scope can never be widened from a query (a tested invariant).
- **Access scopes inside a tenant** — put a `scope` in the vector metadata
  (`EmbeddableDefinition::metadata()` or `rag_vector_metadata`) and filter with
  `->where('scope', ['in' => $allowed])`.
- **Tamper-evident audit log** — append-only with database-level WORM triggers
  (switch them off with `RAG_AUDIT_DB_TRIGGERS=false` on hosts that reject
  `CREATE TRIGGER`; the model-level guard stays on).

```php
use Sellinnate\RagEngine\Facades\Rag;

// Run work scoped to a tenant (previous tenant restored afterwards):
Rag::forTenant('tenant-7', fn () => Rag::search('q')->get());

// Right to erasure — crypto-shred a tenant:
Rag::kms()->destroyKey('tenant-7');
```

See [docs/concepts/security.md](docs/concepts/security.md) and
[docs/concepts/multi-tenancy.md](docs/concepts/multi-tenancy.md).

## Documentation

The full documentation is hosted at
**[laravel-rag-engine.selli.io](https://laravel-rag-engine.selli.io)**. The
sources live in [`docs/`](docs/) and are built into a static site with
[docmd](https://docmd.io):

```bash
npm install
npm run docs:dev     # local preview
npm run docs:build   # static site into ./site
```

**Start here:**

- 🧠 [What is RAG?](https://laravel-rag-engine.selli.io/getting-started/what-is-rag) — concepts + glossary, from zero.
- 🚀 [Quickstart](https://laravel-rag-engine.selli.io/getting-started/quickstart) — a complete worked example.
- 🏗️ [Architecture](https://laravel-rag-engine.selli.io/concepts/architecture) — how the pieces fit together.
- 📥 [Ingesting content](https://laravel-rag-engine.selli.io/guides/ingestion) · 🔎 [Retrieval & search](https://laravel-rag-engine.selli.io/concepts/retrieval) · 💬 [Generation](https://laravel-rag-engine.selli.io/concepts/generation)
- 🗄️ [Vector stores & configuration](https://laravel-rag-engine.selli.io/concepts/vector-stores) — incl. pgvector Postgres setup.
- 📊 [Evaluating quality](https://laravel-rag-engine.selli.io/guides/evaluation) — recall@k, MRR, `rag:evaluate`.
- 🧩 [Contracts reference](https://laravel-rag-engine.selli.io/reference/contracts) · 🛠️ [Custom drivers](https://laravel-rag-engine.selli.io/guides/custom-drivers)

## Testing & development

```bash
composer test         # run the Pest suite (515 tests)
composer analyse      # PHPStan, level 8
composer format       # Laravel Pint (code style)

# Coverage (needs a coverage driver, e.g. Xdebug/PCOV):
XDEBUG_MODE=coverage vendor/bin/pest --coverage --min=90
```

Quality gates kept green on every change: **515 tests**, **PHPStan level 8**,
**Pint** clean, **≥90% coverage**.

## License

MIT — see [LICENSE.md](LICENSE.md).
