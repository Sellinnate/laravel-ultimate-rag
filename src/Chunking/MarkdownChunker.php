<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Chunking;

use Sellinnate\RagEngine\Contracts\Tokenizer;
use Sellinnate\RagEngine\Data\ParsedDocument;

/**
 * Structure-aware Markdown chunker (FR-CH-04). Splits on heading boundaries so
 * each chunk is a coherent section, and records the heading trail in metadata so
 * downstream contextual headers (FR-CH-08) can use it. Oversized sections are
 * recursively sub-split.
 *
 * A chunk's text is its section rebuilt as "heading\nbody" (the `#` markers
 * are dropped), so it is not a verbatim slice of the source. Its `offset` is
 * still a real source anchor: the UTF-8 character position where the chunk's
 * first line appears in the document.
 */
final class MarkdownChunker extends AbstractChunker
{
    public function __construct(Tokenizer $tokenizer, private readonly ?SentenceSplitter $sentences = null)
    {
        parent::__construct($tokenizer);
    }

    public function chunk(ParsedDocument $document, array $options = []): array
    {
        $size = max(1, (int) $this->option($options, 'size', 1000));
        $text = $document->text;

        $sections = $this->groupByHeading($text);

        if ($sections === []) {
            return [];
        }

        $recursive = new RecursiveCharacterChunker($this->tokenizer, $this->sentences);
        $offsets = new OffsetMap($text);
        $chunks = [];
        $index = 0;

        foreach ($sections as $section) {
            $body = trim($section['body']);

            if ($body === '') {
                continue;
            }

            $extra = array_filter([
                'heading' => $section['heading'],
                'heading_level' => $section['level'],
                'offset_unit' => 'char',
            ], static fn ($v): bool => $v !== null);

            $parts = mb_strlen($body, 'UTF-8') <= $size
                ? [$body]
                // Oversized section: sub-split but keep the heading context on each part.
                : array_map(
                    static fn ($part): string => $part->content,
                    $recursive->chunk(new ParsedDocument($body, $document->mimeType, metadata: $document->metadata), $options),
                );

            $cursor = $section['start'];
            foreach ($parts as $i => $part) {
                // Sub-split parts start strictly after the previous one.
                $cursor = $this->anchor($text, $part, $i === 0 ? $cursor : min($cursor + 1, strlen($text)));
                $chunks[] = $this->makeChunk($part, $index++, $offsets->charOffset($cursor), $document->metadata, $extra);
            }
        }

        return $chunks;
    }

    /**
     * Byte position of the part's first line in the source, searched from the
     * previous anchor (parts are in order); the previous anchor when not found.
     */
    private function anchor(string $text, string $part, int $from): int
    {
        $firstLine = strtok($part, "\n");
        $position = is_string($firstLine) ? strpos($text, $firstLine, $from) : false;

        return $position === false ? $from : $position;
    }

    /**
     * @return list<array{heading: ?string, level: int, body: string, start: int}>
     */
    private function groupByHeading(string $text): array
    {
        $sections = [];
        $heading = null;
        $level = 0;
        $start = 0;
        $buffer = [];

        foreach (preg_split('/\R/u', $text, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [] as [$line, $position]) {
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m) === 1) {
                $sections = $this->pushSection($sections, $heading, $level, $buffer, $start);
                $heading = trim($m[2]);
                $level = strlen($m[1]);
                // Anchor on the heading text, after its "#" marker.
                $start = $position + (int) strpos($line, $m[2]);
                $buffer = [];
            } else {
                $buffer[] = $line;
            }
        }

        return $this->pushSection($sections, $heading, $level, $buffer, $start);
    }

    /**
     * @param  list<array{heading: ?string, level: int, body: string, start: int}>  $sections
     * @param  list<string>  $buffer
     * @return list<array{heading: ?string, level: int, body: string, start: int}>
     */
    private function pushSection(array $sections, ?string $heading, int $level, array $buffer, int $start): array
    {
        $body = trim(implode("\n", $buffer));

        if ($heading === null && $body === '') {
            return $sections;
        }

        $sections[] = [
            'heading' => $heading,
            'level' => $level,
            'body' => trim(($heading !== null ? $heading."\n" : '').$body),
            'start' => $start,
        ];

        return $sections;
    }

    public function name(): string
    {
        return 'markdown';
    }
}
