---
title: "Writing a custom driver"
description: "Add your own embedder, vector store, reranker, LLM, KMS, chunker, tokenizer or parser — without forking the package."
---

# Writing a custom driver

Every swappable part of the engine is a **driver** behind a **contract** (a PHP
interface). To add your own provider, you implement the contract and register
your driver with the matching manager. Your driver then behaves exactly like a
built-in one — selectable by config, swappable, cached.

::: callout info "In plain words"
Need a provider the package doesn't ship (a niche embedding API, your company's
internal vector DB)? You don't fork the package. You write one class that
implements the relevant interface, register it in a service provider, and point a
config connection at it. Three small steps.
:::

## The three steps

1. **Implement the contract** for the capability you're adding.
2. **Register your driver** with the manager (usually in a service provider's
   `boot()`).
3. **Point a config connection at it** and (optionally) make it the default.

## Worked example: a custom embedder

### Step 1 — implement the `Embedder` contract

```php
use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Data\EmbeddingResponse;
use Sellinnate\RagEngine\Data\Usage;

final class AcmeEmbedder implements Embedder
{
    public function __construct(
        private string $apiKey,
        private int $dimensions,
    ) {}

    public function embed(array $texts): EmbeddingResponse
    {
        // Call your provider; it must return list<list<float>> — one vector per input text.
        $vectors = $this->callAcmeApi($texts);

        return new EmbeddingResponse(
            vectors: $vectors,
            model: 'acme-v1',
            dimensions: $this->dimensions,
            usage: new Usage(tokens: 123),   // report tokens for cost tracking
        );
    }

    public function embedOne(string $text): EmbeddingResponse
    {
        return $this->embed([$text]);
    }

    public function dimensions(): int { return $this->dimensions; }
    public function model(): string { return 'acme-v1'; }

    /** @param list<string> $texts @return list<list<float>> */
    private function callAcmeApi(array $texts): array { /* ... */ }
}
```

::: callout tip "Don't start from scratch for HTTP providers"
If your provider speaks an OpenAI-compatible API, extend `HttpEmbedder` /
`OpenAiCompatibleEmbedder` instead of implementing `Embedder` from zero — you'll
only override the endpoint, auth and payload shape. See how the built-in
providers are built in **[Embedding & providers](/concepts/embedding)**.
:::

### Step 2 — register the driver

Register in a service provider's `boot()` so it's available everywhere:

```php
use Sellinnate\RagEngine\Managers\EmbedderManager;

public function boot(): void
{
    $this->app->make(EmbedderManager::class)->extend(
        'acme',                                   // the driver name
        fn (array $config) => new AcmeEmbedder($config['api_key'], $config['dimensions']),
    );
}
```

The closure receives the resolved config block, so read your settings from
`$config`.

### Step 3 — wire it in config

```php
// config/rag-engine.php
'embedders' => [
    'acme' => ['driver' => 'acme', 'api_key' => env('ACME_KEY'), 'dimensions' => 1024],
],
'defaults' => ['embedder' => 'acme'],   // make it the default (optional)
```

Now `Rag::embed(...)`, `Rag::search(...)` and everything else use your driver. Use
it per-call instead with `Rag::embed($texts, 'acme')`.

## The same pattern for every subsystem

`extend($driver, $factory)` is identical across managers:

| Manager | Contract to implement | Adds a custom… |
|---|---|---|
| `EmbedderManager` | `Embedder` | embedding provider |
| `VectorStoreManager` | `VectorStore` | vector database |
| `RerankerManager` | `Reranker` | reranking model |
| `LlmManager` | `Llm` | generation backend |
| `KmsManager` | `KeyManagement` | key-management service |
| `ChunkerManager` | `Chunker` | chunking strategy |
| `TokenizerManager` | `Tokenizer` | token counter |
| `OcrManager` | `Ocr` | OCR engine for scanned PDFs and images ([example](/concepts/parsing#custom-ocr)) |

Method signatures for each contract are in the
**[Contracts reference](/reference/contracts)**.

::: callout warning "Parsers and preprocessing stages are registered differently"
**Parsers** don't use `extend()`. Register an *instance* —
`app(ParserManager::class)->register(new MyParser)` — and the last parser
registered for a MIME type wins. **Preprocessing stages** implement
`PreprocessingStage` and are added to the pipeline via config order.
:::

## Second example: a custom preprocessing stage

Stages are even simpler — implement `PreprocessingStage`:

```php
use Sellinnate\RagEngine\Contracts\PreprocessingStage;
use Sellinnate\RagEngine\Data\ParsedDocument;

final class UppercaseHeadings implements PreprocessingStage
{
    public function process(ParsedDocument $document): ParsedDocument
    {
        // transform and return the document
        return $document;
    }

    public function name(): string { return 'uppercase-headings'; }
}
```

Then enable it in the pipeline order:

```php
'preprocessing' => ['stages' => ['text-cleaner', 'uppercase-headings', 'pii-redactor']],
```

## How it works under the hood

The base `DriverManager` reads a config block, looks at its `driver` key, and
resolves it to either (a) a custom creator you registered with `extend()`, or (b)
a built-in `create<Driver>Driver()` method. Resolved drivers are **cached per
connection name**. It's the standard Laravel manager pattern (`DB`, `Cache`,
`Filesystem`) applied uniformly — so it'll feel familiar.

## Best practices

- **Register in `boot()`**, not `register()`, so the managers exist.
- **Read all settings from `$config`** — never hard-code keys; use `env()` in the
  config block.
- **Report `Usage` tokens** from embedders/LLMs so cost tracking and quotas work.
- **Honour the contract's guarantees** (e.g. an embedder must return exactly one
  vector per input, in order).
- **Add tests** with your driver wired into a config connection, mirroring the
  package's own provider tests.

## Next

- **[Contracts reference](/reference/contracts)** — the interfaces to implement.
- **[Architecture](/concepts/architecture)** — why this pattern exists.
