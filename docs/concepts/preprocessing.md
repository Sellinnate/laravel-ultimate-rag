---
title: "Preprocessing & PII"
description: "Cleaning text, detecting language, and redacting personal data before it ever reaches an embedding provider or the index."
---

# Preprocessing & PII redaction

After parsing produces clean-ish text, **preprocessing** runs a short, ordered
pipeline of stages that normalize it and — crucially — **strip out personal data
before it leaves your control**. This happens *before* embedding, so sensitive
values are never sent to an embedding provider or written into the index.

::: callout info "In plain words"
Think of preprocessing as the hygiene step between "we extracted the text" and
"we send it off to be embedded". It tidies the text up and scrubs out emails,
card numbers, IBANs and the like, so none of that leaks into a third-party API or
your search index.
:::

## The pipeline of stages

Stages run in the order listed under `rag-engine.preprocessing.stages`:

```php
'stages' => ['text-cleaner', 'language-detector', 'pii-redactor'],
```

| Stage | What it does |
|---|---|
| **text-cleaner** | Normalizes to UTF-8 and line endings, collapses runs of spaces, trims line ends, strips control and zero-width characters. **Keeps line breaks** and turns several blank lines into one paragraph break, so chunkers can still split on paragraphs. |
| **language-detector** | Detects the language (IT / DE / EN) by stop-word frequency; stored on the document. |
| **pii-redactor** | Finds and redacts personal data. **On by default.** |

You can reorder or remove stages, or add your own (see
**[Custom drivers](/guides/custom-drivers)** — a stage is a `PreprocessingStage`).

## PII redaction

**PII** = *Personally Identifiable Information*: data that identifies a person.
The redactor detects and removes these types:

- E-mail addresses
- Credit-card numbers (validated with the Luhn checksum, to avoid false hits)
- IBANs (validated with the mod-97 checksum)
- Italian *codice fiscale* and *partita IVA*
- Phone numbers

::: callout warning "Redaction covers the WHOLE document, not just the body"
PII is scrubbed from the flat text **and** from section content, section
metadata, and the document's metadata tree — including the document `title`
used for [contextual headers](/concepts/chunking#contextual-headers). So a card number hiding in a CSV
cell, a JSON field, or a PDF page section gets caught too — not just prose.
:::

### Two strategies: mask vs tokenize

```php
// config/rag-engine.php → security.pii_strategy
'pii_strategy' => 'mask',     // "[EMAIL]"           — irreversible (the default)
'pii_strategy' => 'tokenize', // "[EMAIL:ab12cd]"    — reversible by you, later
```

- **`mask`** (default) — replaces the value with a type label like `[EMAIL]`. The
  original is **gone**. Safest; use it unless you have a concrete need to recover
  the value.
- **`tokenize`** — replaces the value with a stable token (`[EMAIL:ab12cd]`) and
  records a token→original map in the document metadata. An **authorized** part of
  your app can reverse it. The same value always maps to the same token, so
  relationships are preserved.

::: callout tip "Which should I pick?"
Default to **`mask`**. Choose **`tokenize`** only when a downstream, access-
controlled feature genuinely needs to display the real value back to an entitled
user — and treat that map as sensitive data itself.
:::

### Why detection is robust

Real-world data is messy, and the redactor accounts for it: spaced or lowercase
IBANs (`de89 3704 …`), cards separated by dots or non-breaking spaces, and so on.
When a greedy pattern would over-match into the next word, it trims back to the
**longest value that still passes the checksum**, so only the real PII is
replaced — not the surrounding text.

## Turning it off (not recommended)

```php
// config/rag-engine.php
'pii_redaction_enabled' => env('RAG_PII_REDACTION', false),
```

Only disable this if you fully control the content *and* the embedding provider,
and have a documented reason. Leaving it on is the safe default.

## Best practices

- **Keep PII redaction on** for any content that could contain personal data,
  especially when using a third-party (non-self-hosted) embedder.
- **Prefer `mask`** unless a specific, access-controlled feature needs reversal.
- **Treat the tokenize map as sensitive** — it lives in document metadata; guard
  access to it like the data it represents.
- **Remember language detection feeds chunking/retrieval quality** — keep the
  stage enabled for multilingual corpora.

## Next

- **[Chunking](/concepts/chunking)** — splitting the clean text into searchable
  pieces.
- **[Security & BYOK](/concepts/security)** — encryption of content at rest.
