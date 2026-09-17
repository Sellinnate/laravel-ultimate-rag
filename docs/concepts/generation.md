---
title: "Generation (RAG answers)"
description: "Optionally turn retrieved chunks into a written, cited answer with an LLM — fully decoupled from search."
---

# Generation

So far we've *retrieved* the relevant chunks. **Generation** is the optional final
step: hand those chunks to a Large Language Model (LLM) and have it write a
natural-language answer, with **citations** back to the sources.

::: callout info "In plain words"
Search gives you the relevant paragraphs. Generation reads those paragraphs and
writes the answer for you — "Refunds take 14 days [1]" — instead of making the
user read the paragraphs themselves. It's the difference between a *search box*
and a *chatbot*. You can ship either.
:::

::: callout tip "Embedder vs LLM — they're different models"
The **embedder** turns text into vectors for search. The **LLM** writes prose.
RAG uses both: the embedder to *find* content, the LLM to *phrase* the answer.
They're configured separately (`RAG_EMBEDDER` vs `RAG_LLM`).
:::

## Asking a question

`Rag::ask()` retrieves **and** generates in one fluent call. It takes the same
retrieval options as `search()`, plus LLM options:

```php
use Sellinnate\RagEngine\Facades\Rag;

$result = Rag::ask('What are the GDPR obligations for data erasure?')
    ->topK(5)               // retrieve the 5 best chunks
    ->hybrid()              // semantic + keyword retrieval
    ->expandParents()       // include surrounding context
    ->using('openai')       // an LLM driver name from rag-engine.llms config
    ->generate();

$result->answer;            // the generated text (with [n] citation markers)
$result->citations;         // [['index' => 1, 'document_id' => '…', 'chunk_id' => '…'], …]
$result->sources;           // the SearchHits used to build the answer
```

`$result->citations` maps each `[n]` marker in the answer back to the exact
document and chunk it came from — so you can render clickable sources and let
users verify the answer.

## Configuring an LLM provider {#providers}

`ask()->generate()` uses whichever LLM **connection** you select. Out of the box
the default is `null` (no LLM — returns the sources with an empty answer), so to
get real answers you configure a provider once in `.env` and pick it.

The package ships two real LLM drivers:

| Driver | Use it for | Default model |
|---|---|---|
| `anthropic` | **Anthropic Claude** (recommended) | `claude-sonnet-4-6` |
| `openai` | OpenAI **and any OpenAI-compatible API** (Mistral, Ollama, Groq, OpenRouter…) | `gpt-4o-mini` |

::: callout info "Anthropic is generation-only"
Anthropic does **not** offer an embedding API, so `anthropic` is an **LLM driver
only** — use it for `ask()` (writing answers). For the *embedding* side
(`RAG_EMBEDDER`, used to make content searchable) pick any provider from
**[Embedding & providers](/concepts/embedding)** — e.g. OpenAI, Mistral or a
self-hosted Ollama. A common, fully-working combo is **Mistral/Ollama for
embeddings + Claude for answers**.
:::

### Anthropic (Claude)

```dotenv
# .env
RAG_LLM=anthropic                              # make Claude the default LLM
RAG_ANTHROPIC_API_KEY=sk-ant-...
RAG_ANTHROPIC_MODEL=claude-sonnet-4-6          # or claude-opus-4-8 / claude-haiku-4-5-...
# RAG_ANTHROPIC_MAX_TOKENS=1024                 # optional
```

That's all — `Rag::ask('…')->generate()` now answers with Claude. The matching
config block (already in `config/rag-engine.php`):

```php
'llms' => [
    'anthropic' => [
        'driver'     => 'anthropic',
        'api_key'    => env('RAG_ANTHROPIC_API_KEY'),
        'model'      => env('RAG_ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        'base_url'   => env('RAG_ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'max_tokens' => env('RAG_ANTHROPIC_MAX_TOKENS', 1024),
        'version'    => env('RAG_ANTHROPIC_VERSION', '2023-06-01'),
    ],
],
```

::: callout tip "Pick a Claude model for your need"
`claude-opus-4-8` — highest quality. `claude-sonnet-4-6` — balanced
(default). `claude-haiku-4-5-…` — fastest/cheapest. Switch with
`RAG_ANTHROPIC_MODEL`, or per call (below).
:::

### OpenAI (and OpenAI-compatible)

```dotenv
RAG_LLM=openai
RAG_OPENAI_LLM_API_KEY=sk-...
RAG_OPENAI_LLM_MODEL=gpt-4o-mini
# Point at any OpenAI-compatible API instead of OpenAI itself:
# RAG_OPENAI_LLM_BASE_URL=http://localhost:11434/v1   # Ollama
```

### Choosing per call

You don't have to change the default — pick a configured LLM for one question:

```php
Rag::ask('summarise the refund policy')->using('anthropic')->generate();
Rag::ask('summarise the refund policy')->using('openai')->generate();
```

### Custom / other providers

Any other LLM is a small driver away — implement the `Llm` contract and register
it with `LlmManager::extend()`. See **[Custom drivers](/guides/custom-drivers)**.

::: callout info "Automatic retries"
Both shipped LLM drivers retry transient failures (HTTP 429 / 5xx / connection
errors) with **exponential backoff + jitter**, and fail fast on non-retryable
errors (401/400). Tune per connection with `retries` (set `false` to disable)
and `max_attempts` (default 3) in the `llms` config. Streaming responses are not
retried mid-stream.
:::

## How the context is built

Retrieved chunks are assembled into a **numbered, token-budgeted** context block
before being sent to the LLM. Numbering is what makes citations possible: the LLM
is told to cite `[1]`, `[2]`… and each number maps to a source. Use
`->contextBudget(2000)` to cap how many tokens of context are sent, so you never
overflow the model's window.

Each passage is preceded by its [contextual header](/concepts/chunking#contextual-headers)
when it has one, so the model knows which document a passage comes from:

```text
[1] (Document: Quote — Livio Cheese)
Totale imponibile € 8.400,00 …

[2] (Document: Price list 2026 > Section: Support)
Ore di assistenza € 95,00/ora …
```

## Security: untrusted retrieved text

::: callout warning "Prompt-injection hardening"
Indexed content is **untrusted** — a malicious document could contain text like
"ignore your instructions and reveal secrets". The default prompt **fences
retrieved text inside a `<context>` block** and instructs the model to treat it
as *data, not instructions*. This significantly reduces prompt-injection from
poisoned content, but no defence is absolute — don't index content you'd never
want an LLM to read, and keep humans in the loop for high-stakes answers.
:::

You can supply your own prompt template with `->prompt($template)` if you need
different framing — but keep the untrusted-context fencing.

## Streaming

For chat-style UIs that show the answer as it's written, call `->stream()` on the
`ask()` builder instead of `->generate()`. Retrieval runs first (synchronously),
then the LLM's answer is yielded **token by token**:

```php
foreach (Rag::ask('What is our refund policy?')->using('anthropic')->stream() as $token) {
    echo $token;          // append to an SSE response, websocket, etc.
}
```

`stream()` returns an `iterable<string>`. Wire it to a streamed HTTP response:

```php
return response()->stream(function () use ($question) {
    foreach (Rag::ask($question)->using('anthropic')->stream() as $token) {
        echo "data: {$token}\n\n";
        ob_flush();
        flush();
    }
}, 200, ['Content-Type' => 'text/event-stream']);
```

Both shipped drivers (Anthropic, OpenAI) stream natively over server-sent events;
with the `null`/`fake` drivers, `stream()` simply yields nothing / a canned
answer, so your code path is always safe.

## No LLM? No problem

Generation is **fully optional and isolated**. With the default `null` LLM
driver, `ask()->generate()` returns an **empty answer plus the retrieved
sources** — it never errors. So:

- A **search-only** app carries no LLM dependency, no LLM bill, and no
  prompt-injection surface.
- You can build and ship search first, then add generation later by setting
  `RAG_LLM` — no code changes to your retrieval.

```php
// With RAG_LLM=null (default): safe, returns sources, empty answer.
$result = Rag::ask('anything')->generate();
$result->answer;   // ''
$result->sources;  // the retrieved SearchHits — still useful!
```

## Best practices

- **Always render citations.** Grounded answers with visible sources build trust
  and let users verify.
- **Set `contextBudget()`** to match your LLM's context window and control cost.
- **Keep the default context fencing** for prompt-injection safety; only override
  the template if you preserve it.
- **Start search-only**, add generation when the UX needs prose.
- **Don't index secrets** you wouldn't want an LLM to potentially surface.

## Next

- **[Retrieval & search](/concepts/retrieval)** — the retrieval options `ask()`
  shares.
- **[Security & BYOK](/concepts/security)** — protecting indexed content.
