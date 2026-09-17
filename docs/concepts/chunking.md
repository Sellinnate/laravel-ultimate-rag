---
title: "Chunking"
description: "Why documents are split into chunks, the strategies available, and how to choose size, overlap and strategy."
---

# Chunking

**Chunking** splits a document into small passages ("chunks") that become the
unit you search and retrieve. You don't search whole documents — a 50-page PDF is
far too coarse to be a useful answer. You search chunks.

::: callout info "In plain words"
Imagine highlighting the three sentences that answer a question. Chunking
pre-cuts every document into highlight-sized pieces so that, at search time, the
engine can hand back exactly the relevant piece — not the whole file.
:::

## Why chunk size matters

There's a trade-off baked into the chunk size:

- **Too big** → a chunk covers many topics, its vector becomes a blurry average,
  and retrieval gets imprecise. You also waste the LLM's context budget.
- **Too small** → a chunk lacks enough context to be meaningful on its own
  ("...it expires after 30 days." — *what* expires?).

A good default for prose is **~1000 characters with ~200 characters of overlap**.

::: callout tip "What is overlap?"
**Overlap** repeats the end of one chunk at the start of the next, so a fact that
sits on a boundary still appears whole in at least one chunk. The `recursive`
and `sentence` strategies repeat **whole sentences** that fit in the overlap
budget — never half a sentence. ~10–20% of the chunk size is a sensible amount.
:::

Set the defaults in config (or `.env`):

```dotenv
RAG_CHUNK_STRATEGY=recursive   # recursive | sentence | markdown | fixed
RAG_CHUNK_SIZE=1000            # characters
RAG_CHUNK_OVERLAP=200          # characters
```

## Strategies

Every strategy is a swappable driver. Pick with the `strategy` option:

| Strategy | Driver | Best for | Trade-off |
|---|---|---|---|
| `recursive` | `RecursiveCharacterChunker` | General prose (**default**) | Keeps paragraphs whole when they fit, otherwise cuts between sentences; good all-rounder. |
| `sentence` | `SentenceChunker` | Q&A, legal, anything where mid-sentence splits hurt | Packs whole sentences as densely as possible, even across paragraphs. |
| `markdown` | `MarkdownChunker` | Structured docs with headings | Splits on heading boundaries; needs Markdown structure. |
| `fixed` | `FixedSizeChunker` | Uniform windows; token-budget control | Simple and predictable; can split mid-thought. |

```php
use Sellinnate\RagEngine\Facades\Rag;

$chunks = Rag::chunk($parsedDocument, [
    'strategy' => 'recursive',
    'size'     => 1000,   // characters
    'overlap'  => 200,
]);
```

You usually don't call `Rag::chunk()` directly — `Rag::process()` chunks for you
using the config defaults. Pass options to `process()` to override per document.

## Sentence boundaries {#sentence-boundaries}

A chunk that stops in the middle of a sentence ("…the total amount is") is hard
to understand and embeds poorly. The `recursive` and `sentence` strategies
therefore only end a chunk where a sentence ends.

How the two strategies split, from the preferred cut to the last resort:

| Strategy | Split order |
|---|---|
| `recursive` | paragraph (blank line) → sentence → line break → word → character |
| `sentence` | sentence → line break → word → character |

A piece that fits in `size` is never split further. So a sentence is only cut
when that **single sentence** is longer than `size` (for example a table with no
full stops): then it is cut at a line break, else between words.

Sentences are detected by `SentenceSplitter`, tuned for Italian and English (plus
common German abbreviations). A sentence ends at `.`, `!`, `?` or `…` followed by
a space, **except** after:

| Case | Examples |
|---|---|
| Titles and abbreviations | `Sig.`, `Dott.`, `Dott.ssa`, `Prof.`, `ecc.`, `Mr.`, `Dr.`, `etc.`, `vs.` |
| Dotted forms and legal forms | `S.r.l.`, `S.p.A.`, `e.g.`, `i.e.`, `P.IVA` |
| Initials | `J. Smith`, `N. preventivo` |
| Reference abbreviations before a number | `art. 5`, `No. 3`, `pag. 12` |
| A list number at the start of a line | `1. Primo punto` |
| A lowercase word | `ecc. e altro`, `Wait... what` |

Numbers, dates, URLs and e-mail addresses never contain "dot + space", so
`€ 8.400,00`, `3.5`, `17.09.2026`, `https://selli.io/v1.2` and `info@selli.io`
are never split. A blank line always ends a sentence, and so does a line that
starts with a bullet (`•`, `-`, `*`) or a list number (`1.`, `2)`). A **single**
line break does not: extracted text (PDFs especially) wraps sentences across
lines.

Add your own abbreviations (case-insensitive, with or without the dot):

```php
// config/rag-engine.php
'chunking' => [
    'abbreviations' => ['Rep', 'Cod.Fisc'],   // all sentence-aware strategies
],
'chunkers' => [
    'sentence' => ['driver' => 'sentence', 'abbreviations' => ['Cfm']], // one strategy only
],
```

Chunks are **exact slices of the text**: line breaks and paragraph breaks inside
a chunk are kept, and a chunk's `offset` is the character where it starts
(`mb_substr($text, $chunk->offset, mb_strlen($chunk->content)) === $chunk->content`).

## Token-aware chunking

Embedding models have a maximum input length measured in **tokens** (≈ word
pieces), not characters. The `fixed` strategy can measure in tokens so chunks
never exceed the model's budget:

```php
Rag::chunk($doc, ['strategy' => 'fixed', 'unit' => 'tokens', 'size' => 512, 'overlap' => 50]);
```

## Parent-child (small-to-big)

A powerful pattern: embed **small** chunks for precise matching, but return a
**larger** surrounding chunk for context.

```php
Rag::chunk($doc, ['parent_child' => true, 'child_size' => 400, 'parent_size' => 2000]);
```

- **Children** (small) are embedded and searched → precise matches.
- **Parents** (large) are stored once; children reference theirs by index (no
  duplication).
- At retrieval, `->expandParents()` swaps each matched child for its richer parent
  so the LLM (or user) sees full context. See
  **[Retrieval & search](/concepts/retrieval)**.

::: callout tip "When to use parent-child"
Reach for it when your content is dense and precise wording matters (policies,
manuals, contracts) but answers need surrounding context. For short, self-
contained items (FAQ entries, product blurbs) plain chunking is simpler.
:::

## Contextual headers {#contextual-headers}

A chunk that holds the prices of a quote often never names the client. A query
like "how much is the Livio Cheese quote?" can then miss it. **Contextual
headers** fix this: each chunk gets a short header with the document title and,
when known, the section heading:

```
Document: Quote — Livio Cheese > Section: Prices

Total before VAT € 8,400.00 ...
```

Where the header is used, and where it is not:

| Place | Header included? |
|---|---|
| Text sent to the embedder | **Yes**: the header is prepended, so the vector "knows" the document. |
| Hybrid keyword (BM25) scoring | **Yes**: a query naming the document also matches its chunks. |
| Generation context (`Rag::ask()`) | **Yes**, as `[1] (Document: …)` before the passage. |
| `SearchHit::$content` and stored chunk text | **No**: you get the clean source text. |
| `SearchHit::$metadata['context_header']` | Yes, the header on its own. |

**Where the title comes from**, first match wins:

1. The document's `title` metadata: `Rag::ingest($source, ['title' => 'Quote — Livio Cheese'])`, or
   `EmbeddableDefinition::title()` for Eloquent models (see
   **[Eloquent models](/concepts/eloquent-models#document-title)**). A declared title beats
   a title stored inside the file (PDF/HTML).
2. The title stored inside the file (PDF `Title`, HTML `<title>`).
3. The filename.

The title goes through PII redaction like the rest of the document, so an e-mail
in a title becomes `[EMAIL]` in the header too.

Turn headers off with `RAG_CONTEXTUAL_HEADERS=false`
(`rag-engine.chunking.contextual_headers`).

::: callout warning "Existing documents"
Headers are built when a document is (re-)processed. Documents indexed before
v1.4 have none: run `php artisan rag:reindex {tenant}`. Changing a document's
title re-indexes it automatically on the next ingest/sync, even if its content is
unchanged.
:::

## Provenance on every chunk

Each chunk records where it came from: its `offset` in the document (with an
`offset_unit` of `char` or `word` — only token-unit `fixed` chunks count words),
`token_count`, `chunk_index`, `context_header` and the inherited document
metadata. This is what lets a search result link back to
its exact origin — see **[Eloquent models](/concepts/eloquent-models#every-chunk-is-traceable)**.

## Best practices

- **Start with `recursive`, ~1000/200.** Only change it if results disappoint.
- **Use `sentence`** when you want chunks packed as full as possible with whole
  sentences; `recursive` already avoids mid-sentence cuts but prefers to keep
  paragraphs together.
- **Give documents a title.** It is the cheapest way to make every chunk
  findable by the document's name.
- **Use `markdown`** for docs that have a real heading structure.
- **Switch to token units** if you hit your embedder's input limit.
- **Add parent-child** when precise matches need surrounding context at answer
  time.
- **Keep contextual headers on** — they cost little and noticeably improve recall
  of ambiguous chunks.

## Common pitfalls

::: callout warning
- **Huge chunks → vague search.** If relevant results feel "close but not quite",
  your chunks are probably too large.
- **Tiny chunks → context-free answers.** Very small chunks match well but read
  poorly; pair them with parent-child.
- **Changing chunking doesn't update old vectors.** Re-process documents after
  changing chunk size/strategy (`php artisan rag:reindex {tenant}`) so the index
  reflects the new chunks.
- **Overlap is in characters for every strategy.** Before v1.4 the `sentence`
  strategy read `overlap` as a number of sentences.
- **Unknown abbreviations can end a sentence.** If your domain uses one before a
  capitalised word ("Rep. Contratto"), add it to `chunking.abbreviations`.
:::

## Next

- **[Embedding & providers](/concepts/embedding)** — turning chunks into vectors.
