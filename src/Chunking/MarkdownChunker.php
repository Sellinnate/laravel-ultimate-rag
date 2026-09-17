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
 * still an exact source position: the UTF-8 character where the chunk's first
 * character sits in the document.
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
            $body = $section['body'];

            $extra = array_filter([
                'heading' => $section['heading'],
                'heading_level' => $section['level'],
                'offset_unit' => 'char',
            ], static fn ($v): bool => $v !== null);

            if (mb_strlen($body, 'UTF-8') <= $size) {
                $parts = [['text' => $body, 'byte' => 0]];
            } else {
                // Oversized section: sub-split but keep the heading context on each part.
                $bodyOffsets = new OffsetMap($body);
                $parts = array_map(
                    static fn ($part): array => ['text' => $part->content, 'byte' => $bodyOffsets->byteOffset($part->offset)],
                    $recursive->chunk(new ParsedDocument($body, $document->mimeType, metadata: $document->metadata), $options),
                );
            }

            foreach ($parts as $part) {
                $source = $this->sourceByte($section['segments'], $part['byte']);
                $chunks[] = $this->makeChunk($part['text'], $index++, $offsets->charOffset($source), $document->metadata, $extra);
            }
        }

        return $chunks;
    }

    /**
     * Map a byte offset in a rebuilt section body to the source byte offset.
     *
     * @param  non-empty-list<array{0: int, 1: int, 2: int}>  $segments  [body byte, source byte, length]
     */
    private function sourceByte(array $segments, int $bodyByte): int
    {
        $match = $segments[0];

        foreach ($segments as $segment) {
            if ($segment[0] > $bodyByte) {
                break;
            }
            $match = $segment;
        }

        return $match[1] + min(max(0, $bodyByte - $match[0]), $match[2]);
    }

    /**
     * Group the text into sections. Each section's body is rebuilt as
     * "heading\nline\nline…" (markers and surrounding blank lines dropped),
     * with segments mapping every rebuilt line back to its source position.
     *
     * @return list<array{heading: ?string, level: int, body: string, segments: non-empty-list<array{0: int, 1: int, 2: int}>}>
     */
    private function groupByHeading(string $text): array
    {
        $sections = [];
        $heading = null;
        $buffer = [];

        foreach (preg_split('/\R/u', $text, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [] as [$line, $position]) {
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m) === 1) {
                $sections = $this->pushSection($sections, $heading, $buffer);
                // Anchor the heading on its text, after the "#" marker.
                $title = trim($m[2]);
                $heading = [$title, $position + (int) strpos($line, $m[2]), strlen($m[1])];
                $buffer = [];
            } else {
                $buffer[] = [$line, $position];
            }
        }

        return $this->pushSection($sections, $heading, $buffer);
    }

    /**
     * @param  list<array{heading: ?string, level: int, body: string, segments: non-empty-list<array{0: int, 1: int, 2: int}>}>  $sections
     * @param  array{0: string, 1: int, 2: int}|null  $heading  [text, source byte, level]
     * @param  list<array{0: string, 1: int}>  $buffer  [line, source byte]
     * @return list<array{heading: ?string, level: int, body: string, segments: non-empty-list<array{0: int, 1: int, 2: int}>}>
     */
    private function pushSection(array $sections, ?array $heading, array $buffer): array
    {
        // Drop blank lines around the body and trim its outer edges.
        while ($buffer !== [] && trim($buffer[0][0]) === '') {
            array_shift($buffer);
        }
        while ($buffer !== [] && trim($buffer[count($buffer) - 1][0]) === '') {
            array_pop($buffer);
        }

        $lines = [];
        if ($heading !== null && $heading[0] !== '') {
            $lines[] = [$heading[0], $heading[1]];
        }

        $last = count($buffer) - 1;
        foreach ($buffer as $i => [$line, $position]) {
            if ($i === 0) {
                $trimmed = ltrim($line);
                $position += strlen($line) - strlen($trimmed);
                $line = $trimmed;
            }
            if ($i === $last) {
                $line = rtrim($line);
            }
            $lines[] = [$line, $position];
        }

        if ($lines === []) {
            return $sections;
        }

        $body = '';
        $segments = [];
        foreach ($lines as $i => [$line, $position]) {
            if ($i > 0) {
                $body .= "\n";
            }
            $segments[] = [strlen($body), $position, strlen($line)];
            $body .= $line;
        }

        $sections[] = [
            'heading' => $heading[0] ?? null,
            'level' => $heading[2] ?? 0,
            'body' => $body,
            'segments' => $segments,
        ];

        return $sections;
    }

    public function name(): string
    {
        return 'markdown';
    }
}
