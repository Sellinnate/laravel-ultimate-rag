---
title: "Parsing & formats"
description: "How raw files become clean text the engine can index — supported formats, structure preservation, and security."
---

# Parsing & formats

**Parsing** is the very first step of indexing: it takes a raw source (a PDF's
bytes, an HTML page, a CSV) and produces **clean, normalized text plus its
logical structure** (headings, tables, pages). Everything downstream — chunking,
embedding, search — works on that clean text, never the raw bytes.

::: callout info "In plain words"
Different file formats store text in wildly different ways. A parser is a
translator that reads one format and hands back plain text the rest of the engine
can understand — while throwing away noise (HTML `<script>` tags, formatting
markup) and remembering structure (which line was a heading).
:::

## How a parser is chosen

Each format is a separate `Parser` driver, picked automatically by the source's
**MIME type** (the standard "type tag" for content, like `text/csv` or
`application/pdf`). You normally never call a parser directly — `Rag::process()`
does it for you — but you can:

```php
use Sellinnate\RagEngine\Facades\Rag;

$parsed = Rag::parser()->parse($bytes, 'text/csv');

$parsed->text;       // the normalized, header-paired text
$parsed->sections;   // structural parts: headings, table rows, PDF pages…
$parsed->language;   // detected language (filled in during preprocessing)
```

## Built-in parsers

| Format | MIME type | Notes |
|---|---|---|
| Plain text | `text/plain` | Pass-through. |
| Markdown | `text/markdown` | Heading hierarchy becomes sections. |
| HTML | `text/html` | `<script>`/`<style>` stripped; DOM sanitized; no network access. |
| XML | `application/xml` | Hardened against XXE attacks (see below). |
| CSV / TSV | `text/csv` | Each cell paired with its column header, so rows stay meaningful. |
| JSON | `application/json` | Flattened to readable `path: value` lines. |
| DOCX | `application/vnd…wordprocessingml…` | Word files, read via PHP's `ZipArchive` — no external tools. |
| PDF | `application/pdf` | Text-based PDFs, via the optional `smalot/pdfparser` package. |
| Images | `image/png`, `image/jpeg`, `image/webp`, `image/tiff` | Read by the configured **OCR** engine. Unsupported while OCR is `null` (the default). See [Images & OCR](#images-ocr). |

::: callout tip "PDFs are text, not images"
A PDF parser extracts the *text layer* of a PDF. A **scanned** document (an image
of text) has no text layer — for those, enable **OCR** (below).
:::

## Scanned PDFs, images & OCR {#images-ocr}

**OCR** (optical character recognition) reads text from pictures. The engine
uses it in two places:

- **Scanned PDFs.** When a PDF yields little or no extractable text, the PDF
  parser falls back to OCR.
- **Images.** A PNG, JPEG, WebP or TIFF upload (via `Rag::source()->file()` /
  `storage()`, or an Eloquent `addFile()` field) is parsed by sending it to the
  OCR engine.

OCR is a pluggable engine, off by default:

| Driver (`RAG_OCR`) | What it does |
|---|---|
| `null` (default) | No OCR. Scanned PDFs parse to empty, and **images are unsupported**: the pipeline marks the document `failed` (`ParsingException`), and an Eloquent file field follows `eloquent.on_unparsable_file` (skip or fail), the same as before images were supported. |
| `tesseract` | Shells out to the Tesseract binary (and `pdftoppm` to rasterise PDF pages). Supports PNG/JPEG/WebP/TIFF (plus BMP/GIF). |
| *your own* | Any engine registered with `OcrManager::extend()`, e.g. a vision LLM. See below. |

An image is only claimed by the image parser if the OCR engine's `supports()`
returns `true` for its MIME type. If OCR returns no text, the image is treated as
**unparsable**, just like a corrupt file.

Enable it:

```dotenv
RAG_OCR=tesseract
# requires the `tesseract` (and, for PDFs, poppler's `pdftoppm`) binaries on the host
# RAG_OCR_LANG=eng
# RAG_OCR_MIN_CHARS=16     # below this many extracted chars, treat the PDF as a scan and OCR it
```

How the fallback works: after extracting the text layer, if its length is below
`ocr_min_chars` and an OCR engine is configured, the parser OCRs the file and uses
that text instead (marking `metadata.ocr = true`). Text PDFs are unaffected — OCR
only kicks in when there's nothing to extract.

### Bring your own OCR engine {#custom-ocr}

Implement the `Sellinnate\RagEngine\Contracts\Ocr` contract and register it with
`OcrManager::extend()` to use a cloud OCR (AWS Textract, Google Vision, a
vision-capable LLM…). It works through the same interface, with no parser changes.

```php
use Sellinnate\RagEngine\Contracts\Ocr;

final class VisionLlmOcr implements Ocr
{
    public function __construct(private readonly string $model) {}

    public function ocr(string $contents, string $mimeType): string
    {
        // Send the bytes to your vision API and return the transcribed text
        // ('' when nothing could be read).
        return MyVisionClient::transcribe($contents, $mimeType, $this->model);
    }

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'], true);
    }

    public function name(): string
    {
        return 'vision-llm';
    }
}
```

Register it in a service provider's `boot()` method, **before** anything parses:

```php
use Sellinnate\RagEngine\Managers\OcrManager;

public function boot(): void
{
    $this->app->make(OcrManager::class)->extend('vision-llm', function (array $config, string $name) {
        return new VisionLlmOcr(model: $config['model']);
    });
}
```

Then add a named block to `config/rag-engine.php` and select it:

```php
// config/rag-engine.php
'ocr' => [
    'null' => ['driver' => 'null'],
    // ...
    'vision' => ['driver' => 'vision-llm', 'model' => env('APP_OCR_MODEL')],
],
```

```dotenv
RAG_OCR=vision
```

The callback receives the block's config array and its name (`vision`). See
**[Custom drivers](/guides/custom-drivers)** for the general pattern.

## Structure is preserved, not flattened

Parsers keep a document's logical structure as a list of **`DocumentSection`**
objects — headings with their level, individual table rows, PDF pages. This is
what lets structure-aware chunkers (like the Markdown chunker) split on real
boundaries instead of mid-sentence. See **[Chunking](/concepts/chunking)**.

## Security hardening

Parsing **untrusted** files is a classic attack surface. The built-in parsers are
hardened against the common file-parsing exploits:

- **XXE (XML External Entity)** — a malicious XML file can try to make your server
  read local files or call internal URLs via "external entities". The XML parser
  **disables external entities and rejects any `DOCTYPE`** — including sneaky
  UTF-16-encoded ones.
- **Zip bombs (DOCX)** — a tiny `.docx` can decompress to gigabytes. The DOCX
  parser **caps total uncompressed size** (20 MB by default) and reads only the
  fixed `word/document.xml` entry, never attacker-chosen paths.
- **HTML** — parsed with **no network access**; scripts and styles are removed
  before text extraction.

::: callout warning "Always treat ingested files as untrusted"
These protections are on by default, but the safest posture is still to validate
uploads (size, MIME, source) at your application boundary before handing them to
ingestion.
:::

## Adding your own format

Parsing is extensible — register a parser for a new MIME type and it slots in
without touching the pipeline:

```php
use Sellinnate\RagEngine\Parsing\ParserManager;

app(ParserManager::class)->register(new MyEpubParser);
// The last parser registered for a given MIME type wins.
```

Full walkthrough: **[Custom drivers](/guides/custom-drivers)**.

## Best practices

- **Send the correct MIME type** when ingesting from raw bytes, so the right
  parser is chosen.
- **Enable an OCR engine** if you ingest scanned PDFs or images; otherwise they
  contribute no text (or fail as unsupported).
- **Treat OCR output as untrusted text.** Text read from an image goes through
  the same PII redaction and prompt-injection fencing as any other content.
- **Cap upload sizes** in your app, in addition to the engine's built-in limits.

## Next

- **[Preprocessing & PII](/concepts/preprocessing)** — what happens to the text
  after parsing.
