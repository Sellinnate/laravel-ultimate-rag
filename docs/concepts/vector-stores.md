---
title: "Vector stores (storage & config)"
description: "The four vector-store backends, when to use each, and exactly how to configure them — including the Postgres connection and native pgvector."
---

# Vector stores

A **vector store** is the database that holds your chunk vectors and finds the
ones nearest to a query. This page explains the backends the package ships, when
to use each, and — step by step — how to configure them. New to the idea of a
vector? Read **[What is RAG?](/getting-started/what-is-rag)** first.

::: callout info "In plain words"
After a chunk is embedded into a vector (a list of numbers), it has to live
somewhere you can search quickly. That place is the vector store. You pick one
backend, point the package at it in `.env`, and everything else — indexing and
search — stays exactly the same.
:::

## Which backend should I use?

| Backend (`RAG_VECTOR_STORE`) | What it is | Use it when | Scale |
|---|---|---|---|
| `memory` | In-process, in-RAM | Tests & local dev | Tiny; **resets every run** |
| `database` | Portable SQL (any Laravel DB) | Small/medium apps, "just use my DB" | ~up to tens of thousands of chunks |
| `pgvector` | **Native** Postgres + `vector` extension | Production on Postgres, real ANN | Large |
| `qdrant` | A dedicated Qdrant server | Production at scale, any stack | Millions+ |

::: callout tip "`database` vs `pgvector` — the honest difference"
Both can run on Postgres, but they work very differently:

- **`database`** stores vectors as JSON and scores them with a **brute-force scan
  in PHP**. Portable (Postgres/MySQL/SQLite), zero setup, but it reads every
  vector in a namespace per query — fine for thousands, not millions.
- **`pgvector`** uses Postgres's native `vector` column type, an **HNSW index**,
  and the `<=>` distance operators, so scoring and top-k happen **inside Postgres**
  against an index. This is "real" approximate-nearest-neighbour search and needs
  the `vector` extension.
:::

You choose the default in `.env`:

```dotenv
RAG_VECTOR_STORE=database   # memory | database | pgvector | qdrant
```

…or per query, without changing the default:

```php
Rag::search('q')->store('qdrant')->get();
```

All backends implement the same contract, so **switching backend needs no code
changes** — only config and a re-index into the new store.

---

## 1. `memory` — zero config

The default. Vectors live in PHP memory for the current process, so there is
**nothing to configure**:

```dotenv
RAG_VECTOR_STORE=memory
```

::: callout warning "Not for production"
The in-memory store is wiped when the process ends and isn't shared between
workers or requests. It exists so the test suite and local experiments run with
no external services.
:::

---

## 2. `database` — portable SQL store

Keeps vectors in two tables (`rag_vectors`, `rag_vector_namespaces`) created by
the package migration. Works on **Postgres, MySQL and SQLite**, with no
extensions. Scoring is a filtered brute-force scan in PHP — great up to roughly
tens of thousands of chunks.

### Simplest setup — your app's default database

The migration already created the tables on your default connection, so just:

```dotenv
RAG_VECTOR_STORE=database
# RAG_DB_VECTOR_CONNECTION unset → uses your DB_CONNECTION default
```

### Using a specific connection

Define any connection in `config/database.php`, then name it:

```dotenv
RAG_VECTOR_STORE=database
RAG_DB_VECTOR_CONNECTION=pgsql     # a connection NAME from config/database.php
```

If that connection is a *different* database, run the package migrations there:

```bash
php artisan migrate --database=pgsql
```

```php
// config/rag-engine.php
'database' => [
    'driver'     => 'database',
    'connection' => env('RAG_DB_VECTOR_CONNECTION'), // null = app default
    'table'      => 'rag_vectors',
],
```

---

## 3. `pgvector` — native Postgres ANN

This is the "real pgvector": vectors are stored in a `vector(D)` column, indexed
with **HNSW**, and queried with the `<=>` distance operator so Postgres does the
work against the index.

### Prerequisites

- **Postgres** (not MySQL/SQLite).
- The **`vector` extension** must be installable. Managed Postgres (Neon,
  Supabase, RDS, Cloud SQL) all support it. The engine runs
  `CREATE EXTENSION IF NOT EXISTS vector` for you on first use, so the DB user
  needs permission to create it (or have it pre-created by an admin).

::: callout warning "One embedding dimension per store"
A `vector(D)` column has a **fixed dimension**. Set `dimensions` to match your
embedding model (e.g. 1536 for `text-embedding-3-small`, 1024 for `mistral-embed`).
The store refuses an index with a mismatched dimension — a deliberate guard. If
you genuinely need multiple dimensions at once, use the `database` driver or
Qdrant.
:::

### Setup

**Step 1 — a Postgres connection** in `config/database.php`:

```php
'pgsql' => [
    'driver'   => 'pgsql',
    'host'     => env('DB_PG_HOST', '127.0.0.1'),
    'port'     => env('DB_PG_PORT', '5432'),
    'database' => env('DB_PG_DATABASE', 'rag'),
    'username' => env('DB_PG_USERNAME', 'postgres'),
    'password' => env('DB_PG_PASSWORD', ''),
    'charset'  => 'utf8',
    'prefix'   => '',
    'search_path' => 'public',
    'sslmode'  => 'prefer',
],
```

**Step 2 — point the package at it** in `.env`:

```dotenv
RAG_VECTOR_STORE=pgvector
RAG_PGVECTOR_CONNECTION=pgsql          # the connection name above
RAG_PGVECTOR_DIMENSIONS=1536           # MUST match your embedding model
# RAG_PGVECTOR_INDEX=hnsw              # hnsw (default) | ivfflat
```

**Step 3 — index.** On the first write the engine creates the extension, the
`rag_pgvectors` table and the HNSW index automatically. There's no separate
migration to run.

```php
// config/rag-engine.php
'pgvector' => [
    'driver'      => 'pgvector',
    'connection'  => env('RAG_PGVECTOR_CONNECTION'),
    'table'       => env('RAG_PGVECTOR_TABLE', 'rag_pgvectors'),
    'dimensions'  => env('RAG_PGVECTOR_DIMENSIONS', 1536),
    'index'       => env('RAG_PGVECTOR_INDEX', 'hnsw'),
],
```

### Filters run inside Postgres {#pgvector-filters}

Metadata filters (`->where(...)`) are compiled to SQL on the `metadata` `jsonb`
column and applied **before** `ORDER BY … LIMIT`, so a selective filter (e.g.
an access `scope` that matches 1% of the vectors) still returns `topK` hits.
Filter keys and values are always sent as bound parameters. Keys must match
`[A-Za-z0-9_.:-]` (up to 128 characters), otherwise the query throws.

An ANN index can still stop early when a filter is applied after it, so the
engine also tunes the scan per query:

- `hnsw.ef_search` is raised to cover the requested limit (up to pgvector's
  maximum of 1000).
- On **pgvector ≥ 0.8**, filtered queries use **iterative index scans**
  (`hnsw.iterative_scan = strict_order`, or `relaxed_order` for ivfflat), which
  keep reading the index until the limit is filled.
- On **pgvector < 0.8**, which has no iterative scans, filtered queries use an
  **exact scan** instead of the ANN index. Results are correct but slower on
  large tables, so upgrade pgvector if you filter a big corpus.

All settings use `SET LOCAL`, so they only affect that query's transaction.

::: callout tip "Which to pick on Postgres — database or pgvector?"
Start with **`database`** if your corpus is small and you want zero setup. Move
to **`pgvector`** when you have lots of vectors and want index-backed ANN, and
your deployment uses a single embedding model (one dimension).
:::

---

## 4. `qdrant` — a dedicated vector database

[Qdrant](https://qdrant.tech) is a purpose-built vector database with fast ANN
search. Use it for large corpora or when you don't want vectors in your SQL
database. Open-source and EU self-hostable.

**Step 1 — run Qdrant** (Docker is easiest):

```bash
docker run -p 6333:6333 qdrant/qdrant
```

**Step 2 — point the package at it** in `.env`:

```dotenv
RAG_VECTOR_STORE=qdrant
RAG_QDRANT_HOST=http://localhost:6333
RAG_QDRANT_API_KEY=                 # set this if your Qdrant requires auth
# RAG_QDRANT_QUANTIZATION=scalar    # optional: scalar | binary (cuts memory/cost)
```

```php
'qdrant' => [
    'driver'        => 'qdrant',
    'host'          => env('RAG_QDRANT_HOST', 'http://localhost:6333'),
    'api_key'       => env('RAG_QDRANT_API_KEY'),
    'quantization'  => env('RAG_QDRANT_QUANTIZATION'), // scalar | binary | null
],
```

::: callout tip "Quantization = cheaper memory"
`scalar` (int8) cuts memory ~4× with minimal accuracy loss; `binary` is most
aggressive. Leave unset for full precision. Applied when a collection is first
created, so set it before indexing.
:::

The engine creates one Qdrant **collection per tenant namespace** automatically.

---

## Migrating between backends

Vectors are tied to the embedding model that produced them and to the store they
live in — they don't transfer automatically. To switch backend (or embedding
model), **re-index your corpus**:

1. Change `RAG_VECTOR_STORE` (and the backend's env vars).
2. Re-run processing (`Rag::process()` / `ProcessDocumentJob`), or re-save your
   embeddable models.

## Best practices

- **Dev/tests: `memory`. Small/medium prod: `database`. Postgres at scale:
  `pgvector`. Large/any-stack: `qdrant`.**
- **`pgvector`: set `dimensions` to your embedding model's size** before indexing.
- **Run `php artisan migrate --database=<conn>`** when `database` points at a
  non-default connection.
- **Set Qdrant `quantization`** before the first index if memory/cost matters.
- **Re-index after changing backend or embedding model** — vectors aren't
  portable across either.

## Common pitfalls

::: callout warning
- **`pgvector` but "could not create extension"** → the DB user lacks rights;
  have an admin run `CREATE EXTENSION vector;` once, or use a superuser.
- **`pgvector` dimension error** → `RAG_PGVECTOR_DIMENSIONS` doesn't match your
  embedder. Set it to the model's output size and re-index.
- **`pgvector` "Invalid metadata filter key"** → a `where()` key contains
  characters outside `[A-Za-z0-9_.:-]`. Rename the metadata key.
- **The `content` column is empty** → expected with encryption on: chunk text is
  kept encrypted in `rag_chunks` and decrypted at search time. See
  **[Security → plaintext in the vector store](/concepts/security#vector-payload-content)**.
- **`database` + a custom connection but "table not found"** → run the migrations
  on that connection (`php artisan migrate --database=...`).
- **Switched store and search is empty** → you must re-index; vectors don't move
  between stores.
- **Qdrant search fails** → check `RAG_QDRANT_HOST` is reachable and the API key
  (if any) is set.
:::

## Next

- **[Retrieval & search](/concepts/retrieval)** — querying whatever store you chose.
- **[Configuration](/getting-started/configuration)** — all the config blocks.
