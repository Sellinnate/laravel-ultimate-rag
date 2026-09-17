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
        $overlap = max(0, min((int) $this->option($options, 'overlap', 200), $size - 1));

        $lines = preg_split('/\R/u', $document->text) ?: [];
        $sections = $this->groupByHeading($lines);

        if ($sections === []) {
            return [];
        }

        $recursive = new RecursiveCharacterChunker($this->tokenizer, $this->sentences);
        $chunks = [];
        $index = 0;
        $offset = 0;

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

            if (mb_strlen($body) <= $size) {
                $chunks[] = $this->makeChunk($body, $index++, $offset, $document->metadata, $extra);
                $offset += mb_strlen($body);

                continue;
            }

            // Oversized section: sub-split but keep the heading context on each part.
            $sub = $recursive->chunk(new ParsedDocument($body, $document->mimeType, metadata: $document->metadata), $options);

            foreach ($sub as $part) {
                $chunks[] = $this->makeChunk($part->content, $index++, $offset + $part->offset, $document->metadata, $extra);
            }

            $offset += mb_strlen($body);
        }

        return $chunks;
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{heading: ?string, level: int, body: string}>
     */
    private function groupByHeading(array $lines): array
    {
        $sections = [];
        $heading = null;
        $level = 0;
        $buffer = [];

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m) === 1) {
                $sections = $this->pushSection($sections, $heading, $level, $buffer);
                $heading = trim($m[2]);
                $level = strlen($m[1]);
                $buffer = [];
            } else {
                $buffer[] = $line;
            }
        }

        return $this->pushSection($sections, $heading, $level, $buffer);
    }

    /**
     * @param  list<array{heading: ?string, level: int, body: string}>  $sections
     * @param  list<string>  $buffer
     * @return list<array{heading: ?string, level: int, body: string}>
     */
    private function pushSection(array $sections, ?string $heading, int $level, array $buffer): array
    {
        $body = trim(implode("\n", $buffer));

        if ($heading === null && $body === '') {
            return $sections;
        }

        $sections[] = [
            'heading' => $heading,
            'level' => $level,
            'body' => trim(($heading !== null ? $heading."\n" : '').$body),
        ];

        return $sections;
    }

    public function name(): string
    {
        return 'markdown';
    }
}
