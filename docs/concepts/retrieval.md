---
title: "Retrieval & search"
description: "Find the most relevant chunks for a query — with filters, hybrid search, MMR, reranking and context expansion, explained option by option."
---

# Retrieval & search

Retrieval is the part your users feel: they ask something, and they get back the
most relevant chunks. It's a **fluent builder** — you start with
`Rag::search($text)` and chain options, then call `->get()`.

::: callout info "In plain words"
You give it a question in plain language; it returns the passages that best
answer it, ranked by relevance. Under the hood it embeds your question into a
vector and asks the vector store for the nearest chunk-vectors — but you don't
need to think about any of that. New here?
**[What is RAG?](/getting-started/what-is-rag)** explains the ideas.
:::

## The simplest search

```php
use Sellinnate\RagEngine\Facades\Rag;

$hits = Rag::search('how do refunds work?')->topK(5)->get();

foreach ($hits as $hit) {
    $hit->score;       // relevance (higher = more relevant)
    $hit->content;     // the chunk text
    $hit->documentId;  // which Document it came from
    $hit->metadata;    // tags, heading, source_ref, parent_content, ...
}
```

`->get()` returns an array of `SearchHit` objects. `->first()` returns the top
one (or `null`); `->count()` returns how many matched.

## Every option, explained

Chain only what you need — each method returns the builder:

| Method | What it does | When to use |
|---|---|---|
| `->topK(5)` | Return the 5 best chunks. | Always — pick how many results you want. |
| `->threshold(0.4)` | Drop hits below this relevance score. | Cut low-quality noise before showing users. |
| `->where('tag', 'handbook')` | Keep only chunks whose metadata `tag` equals `handbook`. | Scope to a subset (by type, owner, source…). |
| `->filter([...])` | Apply several metadata conditions at once. | Multiple filters together. |
| `->hybrid()` | Combine semantic + keyword search (merged with RRF). | Queries with exact terms/codes/names. |
| `->mmr(0.6)` | Diversify results so they're not near-duplicates. | When top hits are too similar. |
| `->rerank()` | Re-score the top hits with a more accurate model. | Squeeze out maximum precision. |
| `->expandParents()` | Replace matched child chunks with their larger parents. | With parent-child chunking, for context. |
| `->contextBudget(2000)` | Trim results to fit this many tokens. | Feeding results to an LLM. |
| `->dedup()` | Remove **exact duplicate** chunks (identical text). | Noisy corpora with repeated text. |
| `->expandQueries(3)` | Rephrase the query into N variants with an LLM, retrieve each, fuse (multi-query). | Improve recall on differently-worded questions. |
| `->fetch(50)` | How many candidates to pull *before* rerank/MMR trim to `topK`. | Tune recall vs cost. |
| `->using('openai')` | Use a specific embedder for the query. | Match the one you indexed with. |
| `->store('qdrant')` | Query a specific vector store. | Override the default backend. |
| `->namespace('...')` | Search a specific namespace. | Advanced multi-namespace setups. |

### Filtering on metadata {#filters}

`where()` and `filter()` match against the metadata stored in each vector (see
[Eloquent models](/concepts/eloquent-models#filterable-metadata) and
[Ingesting content](/guides/ingestion#vector-metadata) for how to put it there).
The same rules apply on every vector store:

| Filter | Matches when… |
|---|---|
| `->where('scope', 'hr')` | the value is **exactly** `'hr'` (strict: `'1'` never equals `1`). |
| `->where('scope', null)` | the key is missing or `null`. |
| `->where('scope', ['hr', 'internal'])` | the value is one of the list. |
| `->where('scope', ['in' => [...]])` / `['nin' => [...]]` | the value is / is not in the list (`nin` also matches a missing key). |
| `->where('scope', ['eq' => 'hr'])` / `['neq' => 'hr']` | equal / not equal (`neq` also matches a missing key). |
| `->where('year', ['gte' => 2024, 'lt' => 2026])` | range: **numbers with numbers, strings with strings**. Strings compare byte by byte, so ISO dates (`'2026-01-31'`) work. A missing key or a mismatched type never matches. |

**List values.** When the stored value is a list (e.g. `'scopes' => ['hr', 'finance']`),
filters look at its **elements**:

| Filter on `scopes = ['hr', 'finance']` | Result |
|---|---|
| `->where('scopes', 'hr')` | matches (the list contains `'hr'`) |
| `->where('scopes', ['in' => ['legal', 'finance']])` | matches (at least one element is allowed) |
| `->where('scopes', ['nin' => ['hr']])` | **no** match (an element is excluded) |
| `->where('scopes', ['neq' => 'finance'])` | **no** match |
| `->where('scopes', null)` | no match (`null` matches a missing key, `null` or an **empty** list) |
| `->where('scopes', ['eq' => ['hr', 'finance']])` | matches only this exact list |

Several conditions (several `where()` calls, or one `filter([...])`) must **all**
match. An unknown operator throws a `RagException`. An empty `in` list matches
nothing, so a user with no allowed scopes gets no results.

::: callout info "Qdrant range filters are numeric"
Qdrant only supports ranges on numbers. A string range (such as an ISO date) on
the `qdrant` store throws; store dates as timestamps if you need to filter them
there. All other rules above apply to every store.
:::

::: callout tip "Access control with a scope filter"
Store an access `scope` (or a list of `scopes`) on every vector and always
filter on the scopes the current user is allowed to see:
`->where('scope', ['in' => $allowedScopes])`. With a list, a document is
returned when **any** of its scopes is allowed. The tenant boundary is
enforced by the engine; scopes *inside* a tenant are yours to enforce, on every
query.
:::

### A fully-loaded example

```php
$hits = Rag::search('how does envelope encryption work?')
    ->topK(5)             // 5 results
    ->threshold(0.3)      // ignore weak matches
    ->where('source', 'handbook')
    ->hybrid()            // semantic + keyword
    ->mmr(0.6)            // diversify
    ->rerank()            // precision pass
    ->expandParents()     // small-to-big context
    ->contextBudget(2000) // fits an LLM prompt
    ->get();
```

::: callout tip "Start simple, add options only when needed"
`->topK()` + `->threshold()` covers most apps. Reach for `hybrid`, `mmr` and
`rerank` to fix specific symptoms (missed exact terms, repetitive results, almost-
right ranking) — not by default.
:::

## Understanding the advanced options

- **Hybrid search** runs your query through *both* semantic (vector) and keyword
  (BM25) search, then fuses the two ranked lists with **RRF (Reciprocal Rank
  Fusion)**. It rescues queries where exact tokens matter — error codes, names,
  acronyms — that pure semantic search can miss.
- **MMR (Maximal Marginal Relevance)** re-orders results to balance relevance
  with *variety*. The number (0–1) is how much to favour relevance over
  diversity; `0.6` leans relevant. Use it when your top 5 are five rewordings of
  the same sentence.
- **Reranking** sends the top candidates to a slower, more accurate model (a
  *cross-encoder*) that reads the query and each chunk together. It noticeably
  improves the final ordering at some latency/cost. The package ships real
  reranker drivers — see [configuring a reranker](#reranking-providers) below.
- **Multi-query** (`expandQueries`) asks an LLM for several rephrasings of the
  question, retrieves each, and fuses the results with RRF — improving recall
  when users phrase things differently from your content. It needs a configured
  LLM (see [Generation](/concepts/generation#providers)); with the `null` LLM it
  safely falls back to the single original query.
- **Parent expansion** (`expandParents`) pairs with parent-child
  [chunking](/concepts/chunking): you match on precise small chunks but return
  their larger parents so the answer has context.

## Reranking providers {#reranking-providers}

`->rerank()` uses whichever reranker `connection` you select. The default is
`null` (no reranking). The package ships two real cross-encoder drivers:

| Driver | Provider | Notes |
|---|---|---|
| `cohere` | Cohere Rerank | `rerank-v3.5`, `rerank-multilingual-v3.0` |
| `jina` | Jina reranker | `jina-reranker-v2-base-multilingual` (EU) |

```dotenv
# .env — make Cohere the default reranker
RAG_RERANKER=cohere
RAG_COHERE_RERANK_API_KEY=...        # falls back to RAG_COHERE_API_KEY if unset
```

```php
Rag::search('q')->rerank()->get();           // default reranker
Rag::search('q')->rerank('jina')->get();     // a specific one for this call
```

Need a different provider? Implement the `Reranker` contract and register it —
see **[Custom drivers](/guides/custom-drivers)**.

Both reranker drivers retry transient failures (429/5xx/connection) with
exponential backoff; tune with `retries` / `max_attempts` in the `rerankers`
config.

## Indexing (so there's something to search)

A document must be processed before it can be retrieved. Either use the high-level
flow:

```php
$document = Rag::ingest(Rag::source()->text($body, ['document_key' => 'doc-1']));
Rag::process($document);                    // parse → chunk → embed → store
```

…or, if you already have a parsed document and chunks, index them directly:

```php
$chunks = Rag::chunk($parsed, ['parent_child' => true]);
Rag::index($document, $chunks);
```

::: callout info "Re-indexing is safe"
Re-indexing a document **atomically replaces** its previous generation: new
vectors are written *before* the old ones are removed, so a failure half-way
never leaves you with a broken or empty index.
:::

## Multi-tenant isolation is automatic

Every search is **always** scoped to the current tenant — you cannot accidentally
search across tenants.

```php
// To search as another (authorized) tenant, set the context explicitly:
$results = Rag::forTenant('tenant-42', fn () => Rag::search('q')->get());
```

::: callout warning "You cannot widen scope from a query"
There is intentionally no `forTenant()` on the search builder, and a `tenant_id`
in `->where(...)` can never override the ambient tenant — the engine overwrites
it with the current one. Cross-tenant isolation is a tested invariant, not a
convention. See **[Multi-tenancy](/concepts/multi-tenancy)**.
:::

## Choosing a vector store

| Driver | Backend | Use when |
|---|---|---|
| `memory` | In-process | Tests and local dev (resets each run). |
| `database` | Portable SQL (Postgres/MySQL/SQLite) | Small/medium corpora on your existing DB. |
| `pgvector` | Native Postgres ANN (`vector` + HNSW) | Postgres at scale, index-backed search. |
| `qdrant` | Qdrant (EU self-hostable) | Large corpora, any stack. |

Switch per query with `->store('qdrant')`, or set the default in config. All three
share the same contract, so changing backend needs **no code changes** — only a
re-index into the new store. For full setup of each (including **where to put the
Postgres connection for pgvector**), see **[Vector stores](/concepts/vector-stores)**.

## Best practices

- **Always set `topK` and a `threshold`** — unbounded, unfiltered results are
  rarely what users want.
- **Filter early with `where()`** to keep searches scoped and fast.
- **Query with the same embedder you indexed with.**
- **Add `hybrid()` for keyword-y queries**, `rerank()` when ranking is "almost
  right", `mmr()` when results repeat.
- **Use `contextBudget()` before feeding an LLM** so you never overflow its
  context window.

## Common pitfalls

::: callout warning
- **Empty results?** The document may not be processed yet, or your `threshold`
  is too high.
- **Irrelevant results?** Likely the `fake` embedder — switch to a real one.
- **Expecting cross-tenant results?** They won't appear; that's by design.
- **`rerank()` does nothing?** Configure a real reranker — set `RAG_RERANKER` to
  `cohere` or `jina` (the default is `null`, which is a no-op).
- **`expandQueries()` returns the same as a plain search?** It needs a configured
  LLM (`RAG_LLM`); with the `null` LLM it falls back to the original query.
:::

## Next

- **[Generation (RAG)](/concepts/generation)** — turn retrieved chunks into a
  written answer.
- **[Orchestration & jobs](/concepts/orchestration)** — index at scale.
