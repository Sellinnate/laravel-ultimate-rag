---
title: "Installation"
description: "Add the RAG Engine package to a Laravel app, publish its config and migrations, and verify the install."
---

# Installation

This page gets the package installed and verified. It takes about five minutes
and needs no external services or API keys — the defaults run entirely offline.

## Requirements

| Requirement | Version | Why |
|---|---|---|
| PHP | **8.2+** | The package uses modern PHP features (enums, readonly, typed properties). |
| Laravel | **11, 12 or 13** | Auto-discovery, queues, the container and Eloquent. |
| A database | any Laravel-supported | Stores documents, chunks and metadata. SQLite is fine to start. |

::: callout info "You do NOT need a special vector database to begin"
The default vector store is **in-memory** (great for tests and local dev), and a
SQL-backed **pgvector** store works on plain Postgres/MySQL/SQLite. A dedicated
vector database like Qdrant is only needed at larger scale — see
**[Retrieval & search](/concepts/retrieval)**.
:::

## Step 1 — Install via Composer

```bash
composer require sellinnate/rag-engine
```

Laravel's **package discovery** automatically registers the service provider and
the `Rag` facade — there's nothing to add to `config/app.php`.

## Step 2 — Publish config and migrations

```bash
# Copy config/rag-engine.php into your app so you can edit it:
php artisan vendor:publish --tag="rag-engine-config"

# Copy the database migrations into your app:
php artisan vendor:publish --tag="rag-engine-migrations"

# Create the tables:
php artisan migrate
```

This creates the engine's tables:

| Table | Holds |
|---|---|
| `rag_documents` | Each ingested source (encrypted), with version & metadata. |
| `rag_chunks` | The passages documents are split into (encrypted at rest). |
| `rag_embeddings` | Bookkeeping linking chunks to their stored vectors. |
| `rag_data_keys` | Wrapped per-item encryption keys (DEKs). |
| `rag_ingestion_runs` | A record of each processing run. |
| `rag_usage_records` | Token/cost usage per tenant. |
| `rag_audit_entries` / `rag_audit_anchors` | The tamper-evident audit log. |
| `rag_shredded_tenants` | Tenants that were crypto-shredded (they can't be re-created). |
| `rag_vectors` / `rag_vector_namespaces` | Vectors for the portable `database` vector store. |
| `rag_kms_keys` | Encrypted tenant keys when `RAG_KMS_STORE=database` (own migration; created on `RAG_KMS_CONNECTION` if set). |

::: callout info "Why the audit table can't be edited"
The audit log is **append-only**: database-level triggers reject any `UPDATE` or
`DELETE` on it. That makes the security trail tamper-evident — see
**[Security & BYOK](/concepts/security)**.
:::

::: callout warning "Managed MySQL that rejects CREATE TRIGGER"
If `php artisan migrate` fails creating the audit triggers (no `SUPER`
privilege / binary-logging restrictions), set `RAG_AUDIT_DB_TRIGGERS=false`
before migrating. The model-level guard stays on. See
**[Audit log triggers](/concepts/security#audit-triggers)**.
:::

### Upgrading an existing install {#upgrading}

New package versions can add migrations. Publishing again only copies the **new**
files (existing ones are skipped), so after `composer update` run:

```bash
php artisan vendor:publish --tag="rag-engine-migrations"
php artisan migrate
```

**Upgrading from v1.3 to v1.4**

v1.4 changes how text is cleaned and chunked. No migration is needed.

1. `composer update sellinnate/rag-engine`.
2. Optional: copy the new keys from the package's `config/rag-engine.php` into
   your published copy (`parsing.pdf.*`, `chunking.abbreviations`, and the new
   `env()` calls on `chunking.chunk_size`, `chunk_overlap` and
   `contextual_headers`). The defaults apply without them.
3. Optional: give your Eloquent models a title with
   `EmbeddableDefinition::title()` (see [Document title](/concepts/eloquent-models#document-title)).
4. For each tenant, run `php artisan rag:reindex {tenant}`. Existing chunks were
   cut by the old rules and have no contextual header until they are
   re-processed. Search keeps working meanwhile.
5. If you use the `sentence` strategy and passed `overlap` as a number of
   sentences, pass characters now (e.g. `200`).

**Upgrading from v1.2 to v1.3**

1. `composer update sellinnate/rag-engine`.
2. `php artisan vendor:publish --tag="rag-engine-migrations"`. Your already
   published `create_rag_engine_tables` migration is skipped; only the new
   `create_rag_kms_keys_table` migration is copied. v1.3 adds **no columns or
   indexes** to existing tables.
3. `php artisan migrate`. This creates `rag_kms_keys` on `RAG_KMS_CONNECTION`
   (or the default connection).
4. Optional: copy the new keys from the package's `config/rag-engine.php`
   (`security.vector_payload_content`, `audit`, `kms.local.connection`,
   `tables.kms_keys`) into your published copy. The defaults apply without them.
5. For each tenant, run `php artisan rag:reindex {tenant}`. This strips legacy
   plaintext from the vectors and adds your filterable metadata to them.
6. For each tenant, run `php artisan rag:reconcile {tenant} --prune`. This
   deletes orphan embedding records left behind by earlier purges.

`RAG_AUDIT_DB_TRIGGERS` only matters when the main migration runs. If your v1.2
migration has already run, nothing changes. If it hasn't and your host rejects
triggers, re-publish it with `--force` (or edit your copy) before migrating.

## Step 3 — Verify the install

Run this in `php artisan tinker` (or a quick route). It confirms the engine is
wired up and shows the default drivers:

```php
use Sellinnate\RagEngine\Facades\Rag;

Rag::tenant()->id();            // 'default'  — the current tenant
Rag::embedder()->dimensions();  // 8          — the deterministic 'fake' embedder
Rag::vectorStore()->name();     // 'memory'   — the in-memory vector store
```

If those three calls return values without error, you're ready.

::: callout tip "What the defaults mean"
Out of the box the package uses **zero-network, deterministic drivers** so your
test suite runs offline and for free:

- **`fake` embedder** — turns text into fixed pseudo-vectors. Perfect for tests;
  **not** for real search (it doesn't understand meaning).
- **`memory` vector store** — keeps vectors in process; resets each run.
- **`local` KMS** — manages encryption keys locally.

You move to production drivers (Ollama/Mistral/OpenAI embeddings,
Qdrant/pgvector storage, cloud KMS) purely through `.env` and config — your
application code never changes.
:::

## Step 4 — Configure a real embedder (before building features)

The `fake` embedder is for tests. The moment you want search that actually works,
switch to a real embedding model. The cheapest local option:

```bash
# https://ollama.com — runs an embedding model on your machine, no API key.
ollama pull nomic-embed-text
```

```dotenv
# .env
RAG_EMBEDDER=ollama
RAG_OLLAMA_BASE_URL=http://localhost:11434
```

Other providers (OpenAI, Mistral, Cohere, Azure, Voyage, Gemini, Hugging Face)
are one env-var away — see **[Embedding & providers](/concepts/embedding)**.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `Class 'Rag' not found` | Run `composer dump-autoload`; ensure discovery isn't disabled in `composer.json`. |
| Migration errors about existing tables | You may have published twice; check `database/migrations` for duplicates. |
| `Rag::vectorStore()->name()` isn't `memory` | Something set `RAG_VECTOR_STORE`; clear it or run `php artisan config:clear`. |
| Search returns nonsense | You're on the `fake` embedder — do Step 4. |

## Next steps

1. **[Quickstart](/getting-started/quickstart)** — build a working feature now.
2. **[Configuration](/getting-started/configuration)** — understand every knob.
