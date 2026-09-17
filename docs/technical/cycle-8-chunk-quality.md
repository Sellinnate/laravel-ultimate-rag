# Technical Notes — Cycle 8: chunk quality (v1.4.0)

Context: a real 4-page Italian quote ("preventivo") was ingested through an
Eloquent embeddable (`add('Document', …)->add('Category', …)->addFile('Content',
…, 'application/pdf')->metadata([...])`) with the default `recursive` strategy
(1000/200). Inspecting the chunks showed four problems. Each was reproduced with
a failing test before the fix (`PdfCleanupTest`, `DocumentContextTest`,
`SentenceSplitterTest`, `SentenceBoundaryChunkingTest`).

## A — Most chunks did not know which document they came from

Only chunk 0 contained the title. `ContextualHeaderEnricher` read
`ParsedDocument::metadata['title'|'filename']`, but an Eloquent document is
parsed as `text/plain` with only a (null) filename in the parse context, so no
header was produced for any chunk.

Fix:

- `IngestionPipeline` reads the document's declared `title` / `filename`
  (`IngestionPipeline::documentContext()`), passes them in the parse context and
  merges them into the parsed metadata. A declared title beats a title read from
  the file; the filename is only added when the parser did not set one. This
  happens before preprocessing, so the title is PII-redacted.
- `EmbeddableDefinition::title()` declares a model's title
  (`titleValue()` falls back to a string `title` given to `metadata()`).
  `EmbeddableCompiler` carries it on `CompiledEmbeddable::$title`, and
  `ModelEmbedder` writes it to the document metadata and to
  `rag_vector_metadata`.
- The header stays out of `TextChunk::$content`. `TextChunk::embeddableText()`
  (used by `Indexer`) already prepended it for embedding; the enricher now also
  stores it as `context_header` chunk metadata, which reaches the chunk row and
  the vector payload. `KeywordScorer` scores `context_header + content`, and
  `ContextAssembler` prints `(header)` before each passage. `SearchHit::$content`
  is unchanged (hydrated from the encrypted chunk text).
- Re-indexing: `Ingestor::INDEXED_METADATA_KEYS = ['rag_vector_metadata',
  'title']`. A duplicate (same bytes) whose title changed is flagged `pending`,
  for keyed sources and for un-keyed sources that pass a `title`. Existing
  documents get headers with `rag:reindex`.

The title already reached vector payloads for PDF/HTML documents (inherited
chunk metadata), so payload exposure is unchanged in kind.

## B — Chunks were cut mid-sentence

`RecursiveCharacterChunker` split on `"\n\n"`, `"\n"`, `". "`, `" "`, `""`.
PDF text has a line break at the end of every printed line, so the `"\n"`
level cut sentences; the `". "` level dropped the period and split on
abbreviations. `SentenceChunker` split on `(?<=[.!?])\s+`, and read `overlap`
as a sentence count, while `ChunkingService` passes the character overlap
(200): every chunk advanced by one sentence.

Design: one shared engine, `TextPacker` (internal), with a boundary hierarchy
per strategy, and one boundary detector, `SentenceSplitter`:

- `recursive`: paragraph → sentence → line → word → character.
- `sentence`: sentence → line → word → character.
- A piece that fits in `size` is never split further. Pieces are merged
  greedily; overlap repeats whole trailing pieces within `overlap` characters,
  or the trailing whole sentences of a piece too long to repeat. A tail that
  would push the next chunk past `size` is dropped from the front.
- Everything works on byte ranges of the source; chunks are exact slices, and
  `OffsetMap` converts byte offsets to character offsets incrementally.

Improving the recursive chunker (the default) instead of switching the default
to `sentence` fixes existing installs without a strategy change and keeps
paragraphs together.

`SentenceSplitter` rules: a boundary is `[.!?…]+` plus closing quotes/brackets
followed by whitespace, unless the next character is lowercase, or (single `.`)
the word is an initial, a dotted form (`(\p{L}{1,5}\.)+\p{L}{1,5}`), a known
abbreviation, a reference abbreviation followed by a digit, or a 1–3 digit
list number at the start of a line. Blank lines and bullet/numbered lines are
always boundaries; a single line break is whitespace. Extra abbreviations come
from `chunking.abbreviations` and per-connection `chunkers.<name>.abbreviations`
(`ChunkerManager::sentenceSplitter()`).

`SentenceChunker` offsets are now characters (`offset_unit = char`, was
`sentence`).

## C — Repeated page headers/footers

Every page of the quote started (in stream order) with
`Sellinnate S.r.l. | Viale Belfiore 55, 50144 Firenze | P.IVA: …\t<page>`, and
a diagram left `→ → →` on its own line.

Fix in `PdfParser` (all configurable under `parsing.pdf`):

- Lines are trimmed and blank lines dropped per page.
- Symbol-only lines (arrows, box drawing, geometric shapes, bullets, dingbats,
  dashes and ASCII rule characters) are dropped.
- For PDFs with ≥ `repeated_line_min_pages` (min 2) pages, a line within the
  first/last `repeated_line_edge_lines` lines of a page whose key (lowercased,
  digits → `#`, whitespace collapsed) appears on at least
  `max(2, ceil(threshold × pages))` pages is dropped from those edge positions.
  Mid-page repeats are kept. Invalid threshold/edge settings throw.
- Page sections contain the cleaned page text.

Tests generate PDFs in memory with `tests/Support/PdfBuilder` (Helvetica +
WinAnsi, plus a ToUnicode-mapped font for arrows), so no binary fixture is
added.

## D — Structure was flattened

`TextCleaner` and the Eloquent compiler already kept line and paragraph breaks.
The flattening came from `RecursiveCharacterChunker`, which re-joined its pieces
with a single space ("Quote [File]"), and from `HtmlParser`, which used
`textContent` (`<p>a</p><p>b</p>` → `ab`) and collapsed blank lines.

Fix:

- Chunks are exact source slices (B).
- `PdfParser` re-joins visual line wraps: a line not ending in `.!?:;…`
  followed by a line starting (after opening punctuation) in lowercase is
  joined with a space (no space after a trailing `letter-`), also across page
  breaks; pages are otherwise separated by a blank line.
- `HtmlParser` walks the DOM: block elements become paragraphs, `li`/`tr`/`dt`/
  `dd`/`br` become lines, cells are tab-separated, `<pre>` keeps its line
  breaks, source whitespace collapses. Private-use markers are resolved after
  the walk.
- `TextCleaner` trims line ends before collapsing blank lines, so whitespace-only
  lines no longer leave extra paragraph breaks.

### Headings in PDFs: not detected

smalot's `getText()` gives no font or spacing information. Positions
(`getDataTm()`) show paragraph gaps and font data exists behind a config flag,
but mapping positioned items back to `getText()` lines is fragile across the
`^2.0` range, and line-shape heuristics (short line, no final punctuation)
mislabel `Data: 17 settembre 2026`, `€ 3.900,00` or `Descrizione\tImporto` in
the real quote. PDF pages therefore get no heading sections; headings stay on
their own line and the sentence splitter keeps them attached to the text that
follows.

## Behaviour changes

- Chunk text is an exact source slice: line/paragraph breaks and sentence
  periods are kept (before, pieces were joined with spaces and `". "` splits lost
  the period).
- `sentence` strategy: `overlap` is characters; offsets are characters.
- PDF text: repeated headers/footers and symbol lines removed, wraps joined.
- HTML text: block structure kept.
- Generation context lines carry `(Document: …)`.
- Chunks and vector payloads carry `context_header` (and the declared `title`).
- A duplicate ingest whose `title` changed is flagged `pending`; a keyed
  duplicate is also flagged when it drops a previously declared
  `rag_vector_metadata` or `title`.

Upgrade: `rag:reindex {tenant}` for every tenant.
