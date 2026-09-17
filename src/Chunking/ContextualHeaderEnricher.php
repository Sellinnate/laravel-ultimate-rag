<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Chunking;

use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Data\TextChunk;

/**
 * Contextual chunk headers (FR-CH-08): gives each chunk a header that situates
 * it — `Document: <title> > Section: <heading>` — so a chunk that never names
 * its document (e.g. the price table of a quote) is still found by a query
 * about that document.
 *
 * The title is the document's `title` metadata (a caller-declared title — see
 * `IngestionPipeline` — wins over one read from the file), else its filename.
 *
 * The header is NOT part of `TextChunk::$content` (which stays the verbatim
 * source slice returned by search). It is:
 * - prepended to the text sent to the embedder ({@see TextChunk::embeddableText()});
 * - stored as the chunk's `context_header` metadata, so hybrid keyword scoring
 *   and the generation context can use it.
 */
final class ContextualHeaderEnricher
{
    /** Chunk (and vector payload) metadata key holding the header. */
    public const METADATA_KEY = 'context_header';

    /**
     * @param  list<TextChunk>  $chunks
     * @return list<TextChunk>
     */
    public function enrich(array $chunks, ParsedDocument $document): array
    {
        $title = $this->documentTitle($document);

        return array_map(function (TextChunk $chunk) use ($title): TextChunk {
            $parts = [];

            if ($title !== null) {
                $parts[] = "Document: {$title}";
            }

            $heading = $chunk->metadata['heading'] ?? null;
            if (is_string($heading) && $heading !== '') {
                $parts[] = "Section: {$heading}";
            }

            if ($parts === []) {
                return $chunk;
            }

            $header = implode(' > ', $parts);

            return $chunk->withContextHeader($header)->withMetadata([self::METADATA_KEY => $header]);
        }, $chunks);
    }

    private function documentTitle(ParsedDocument $document): ?string
    {
        foreach (['title', 'filename'] as $key) {
            $value = $document->metadata[$key] ?? null;

            // An unusable value (blank, invalid UTF-8) falls through to the next key.
            $normalized = is_string($value) ? preg_replace('/\s+/u', ' ', $value) : null;

            if (is_string($normalized) && trim($normalized) !== '') {
                return trim($normalized);
            }
        }

        return null;
    }
}
