---
title: "Configuration"
description: "How config/rag-engine.php is organised, how driver selection works, and the best practices for each block."
---

# Configuration

All settings live in **`config/rag-engine.php`** (published in
[Installation](/getting-started/installation)). This page explains how the file
is structured and how to change which provider powers each part of the engine.

::: callout info "The one mental model you need"
The config works **exactly like Laravel's `config/database.php`**. There you
define named *connections* (`mysql`, `sqlite`, `pgsql`) and pick a `default`.
Here you define named *connections* for each subsystem (embedders, vector
stores, LLMs…) and pick a default for each. Selecting a provider = changing one
name. That's the whole idea.
:::

## How driver selection works

Two layers:

**1. `defaults` — which connection each subsystem uses right now.**

```php
'defaults' => [
    'embedder'     => env('RAG_EMBEDDER', 'fake'),
    'vector_store' => env('RAG_VECTOR_STORE', 'memory'),
    'reranker'     => env('RAG_RERANKER', 'null'),
    'kms'          => env('RAG_KMS', 'local'),
    'tokenizer'    => env('RAG_TOKENIZER', 'approximate'),
    'llm'          => env('RAG_LLM', 'null'),
],
```

**2. Named connections — the catalogue of available providers.** Each entry
declares which `driver` (implementation) powers it, plus its settings:

```php
'embedders' => [
    'fake'    => ['driver' => 'fake', 'dimensions' => 8],
    'ollama'  => ['driver' => 'ollama', 'model' => 'nomic-embed-text', 'dimensions' => 768],
    'openai'  => ['driver' => 'openai', 'model' => 'text-embedding-3-small',
                  'dimensions' => 1536, 'api_key' => env('RAG_OPENAI_API_KEY')],
],
```

So `RAG_EMBEDDER=ollama` in `.env` makes every `Rag::embed(...)` /
`Rag::search(...)` call use the `ollama` connection — **no code change**.

::: callout tip "Connection name vs driver"
The *name* (`'openai'`) and the *driver* (`'driver' => 'openai'`) are
independent. You can have two connections — `'fast'` and `'accurate'` — both
using the `openai` driver with different models, and switch between them by name.
:::

### Choosing per call

You don't have to change the default to use another connection once:

```php
Rag::embed($texts, 'openai');           // this call only
Rag::search('q')->using('ollama')->get();
Rag::search('q')->store('qdrant')->get();
Rag::ask('q')->using('openai')->generate();
```

## Where API keys go

::: callout warning "Never hard-code keys in this file"
`config/rag-engine.php` is committed to git and cached by `config:cache`. Every
provider reads its `api_key` from an **`env()`** variable — you set the actual
value in your `.env` (which is *not* committed). A ready-to-copy list of every
variable ships as **`.env.example`** at the package root, and
**[Embedding & providers](/concepts/embedding#credentials)** walks through each
provider.
:::

```dotenv
# .env  — only set the providers you actually use
RAG_EMBEDDER=openai
RAG_OPENAI_API_KEY=sk-...
```

## Every subsystem, and where it's documented

Each subsystem has a `defaults` entry, a named-connections block, and a dedicated
guide:

| Subsystem | `defaults` key / env | Connections block | Guide |
|---|---|---|---|
| Embedding | `embedder` / `RAG_EMBEDDER` | `embedders` | [Embedding & providers](/concepts/embedding) |
| Vector store | `vector_store` / `RAG_VECTOR_STORE` | `vector_stores` | [Vector stores](/concepts/vector-stores) |
| LLM (answers) | `llm` / `RAG_LLM` | `llms` | [Generation](/concepts/generation#providers) |
| Reranker | `reranker` / `RAG_RERANKER` | `rerankers` | [Retrieval → reranking](/concepts/retrieval#reranking-providers) |
| KMS (keys) | `kms` / `RAG_KMS` | `kms` | [Security & BYOK](/concepts/security) |
| Tokenizer | `tokenizer` / `RAG_TOKENIZER` | `tokenizers` | [Chunking](/concepts/chunking) |

::: callout tip "Where do I configure the Postgres connection for pgvector?"
In `config/database.php` (a normal Laravel connection), then point the package at
it with `RAG_PGVECTOR_CONNECTION`. The **[Vector stores](/concepts/vector-stores)**
guide walks through it step by step.
:::

## The main config blocks

### Security

```php
'security' => [
    'encryption_enabled'     => env('RAG_ENCRYPTION_ENABLED', true),  // envelope-encrypt content at rest
    'vector_payload_content' => env('RAG_VECTOR_PAYLOAD_CONTENT'),    // null = plaintext in vectors only when encryption is off
    'cipher'                 => 'aes-256-gcm',
    'pii_redaction_enabled'  => env('RAG_PII_REDACTION', true),       // strip personal data before indexing
    'pii_strategy'           => env('RAG_PII_STRATEGY', 'mask'),       // 'mask' or 'tokenize'
],

'audit' => [
    'db_triggers' => env('RAG_AUDIT_DB_TRIGGERS', true), // false on hosts that reject CREATE TRIGGER
],
```

Encryption and PII redaction are **on by default**, and with encryption on no
chunk text is copied into the vector store. See
**[Security](/concepts/security#vector-payload-content)**,
**[Audit log triggers](/concepts/security#audit-triggers)** and
**[Preprocessing & PII](/concepts/preprocessing)**.

### Key management (local KMS)

```php
'kms' => [
    'local' => [
        'driver'     => 'local',
        'store'      => env('RAG_KMS_STORE', 'array'),   // array | file | database
        'master_key' => env('RAG_KMS_MASTER_KEY'),       // required for database (>= 32 chars)
        'keystore'   => env('RAG_KMS_KEYSTORE', storage_path('rag-engine/kms')), // file store directory
        'connection' => env('RAG_KMS_CONNECTION'),       // database store connection (null = default)
    ],
    // 'aws' => [...]
],
```

Use `database` on multi-node or ephemeral-disk hosting. An unknown `store`
value throws. See **[Local KMS key stores](/concepts/security#kms-key-stores)**.

### Multi-tenancy {#multi-tenancy}

```php
'tenancy' => [
    'isolation'      => env('RAG_TENANCY_ISOLATION', 'namespace'), // only 'namespace' is supported today
    'default_tenant' => env('RAG_DEFAULT_TENANT', 'default'),
    'quotas' => [
        'max_documents'        => null,  // null = unlimited
        'max_corpus_bytes'     => null,
        'max_embedding_tokens' => null,
    ],
],
```

Every document and query is automatically scoped to the current tenant. See
**[Multi-tenancy](/concepts/multi-tenancy)**.

### Eloquent model embedding

```php
'eloquent' => [
    'auto_sync' => env('RAG_ELOQUENT_AUTO_SYNC', true),  // re-index models on save/delete
    'queue'     => env('RAG_ELOQUENT_QUEUE', false),     // do it on a queue (recommended in prod)
    'max_depth' => env('RAG_ELOQUENT_MAX_DEPTH', 3),     // how deep to compose related models
    'namespace' => env('RAG_ELOQUENT_NAMESPACE'),        // null = share the default namespace

    // File fields (addFile): what to do with a non-embeddable file (zip/exe/…).
    'on_unparsable_file' => env('RAG_ELOQUENT_ON_UNPARSABLE_FILE', 'skip'), // skip | fail
    'max_file_bytes'     => env('RAG_ELOQUENT_MAX_FILE_BYTES', 25 * 1024 * 1024),
],
```

See **[Embedding Eloquent models](/concepts/eloquent-models)**.

### Chunking defaults

```php
'chunking' => [
    'default_strategy'   => env('RAG_CHUNK_STRATEGY', 'recursive'),
    'chunk_size'         => env('RAG_CHUNK_SIZE', 1000),     // characters
    'chunk_overlap'      => env('RAG_CHUNK_OVERLAP', 200),   // characters, whole sentences
    'max_tokens'         => 512,
    'abbreviations'      => [],     // extra abbreviations that never end a sentence
    'contextual_headers' => env('RAG_CONTEXTUAL_HEADERS', true), // "Document: <title>" on each chunk
    'parent_child'       => false,  // small-to-big chunking
],
```

See **[Chunking](/concepts/chunking)** for what each option does and how to pick
(and **[Sentence boundaries](/concepts/chunking#sentence-boundaries)** for the
abbreviation rules).

### Parsing clean-up

```php
'parsing' => [
    'pdf' => [
        'strip_repeated_lines'     => env('RAG_PDF_STRIP_REPEATED_LINES', true),     // running headers/footers
        'repeated_line_threshold'  => env('RAG_PDF_REPEATED_LINE_THRESHOLD', 0.6),   // share of pages (0 < x <= 1)
        'repeated_line_min_pages'  => env('RAG_PDF_REPEATED_LINE_MIN_PAGES', 2),     // only PDFs this long
        'repeated_line_edge_lines' => env('RAG_PDF_REPEATED_LINE_EDGE_LINES', 3),    // lines at top/bottom checked
        'strip_symbol_lines'       => env('RAG_PDF_STRIP_SYMBOL_LINES', true),       // "→ → →" lines
        'join_wrapped_lines'       => env('RAG_PDF_JOIN_WRAPPED_LINES', true),       // re-join visual line wraps
    ],
],
```

See **[PDF clean-up](/concepts/parsing#pdf-cleanup)**.

## Production checklist (best practices)

- **Set a real `RAG_EMBEDDER`** (Ollama/Mistral/OpenAI…). The `fake` default is
  for tests only.
- **Keep secrets in `.env`**, never in the committed config. Run
  `php artisan config:cache` in production.
- **Pick a vector store for your scale** — `pgvector` for small/medium on your
  existing DB; `qdrant` for large corpora. See
  **[Retrieval & search](/concepts/retrieval)**.
- **Leave encryption and PII redaction on** unless you have a specific, reviewed
  reason not to.
- **Never run the `array` KMS store in production.** Use `aws`, or
  `RAG_KMS_STORE=database` with a secret `RAG_KMS_MASTER_KEY`.
- **Filter every search on the scopes the user may see** if you store access
  scopes in vector metadata (see [Retrieval → filters](/concepts/retrieval#filters)).
- **Set per-tenant quotas** if you're multi-tenant, to cap runaway cost.
- **Re-index after changing the embedding model** — vectors from different models
  aren't comparable.

::: callout warning "EU-residency note"
The default embedding and KMS drivers are EU-resident or self-hostable. Non-EU
providers (OpenAI, Cohere, Voyage, Gemini) are available but should only be
enabled as an explicit, documented choice.
:::
