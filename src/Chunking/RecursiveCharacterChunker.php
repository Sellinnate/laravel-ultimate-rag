<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Chunking;

use Sellinnate\RagEngine\Contracts\Tokenizer;
use Sellinnate\RagEngine\Data\ParsedDocument;

/**
 * Recursive character chunker (FR-CH-02, the default strategy): splits on a
 * hierarchy of boundaries — paragraph → sentence → line → word → character —
 * and merges adjacent pieces up to `size` characters with `overlap`.
 *
 * A paragraph that fits in `size` is never split, and a paragraph that does
 * not is split between whole sentences ({@see SentenceSplitter}: abbreviations,
 * legal forms, decimals, dates and URLs are not sentence ends). Only a single
 * sentence longer than `size` is cut — at a line break, else between words.
 *
 * Each chunk is the exact source slice it covers (line and paragraph breaks
 * included) and its `offset` is the character where it starts. See
 * {@see TextPacker} for the merge and overlap rules.
 */
final class RecursiveCharacterChunker extends AbstractChunker
{
    private readonly TextPacker $packer;

    public function __construct(Tokenizer $tokenizer, ?SentenceSplitter $sentences = null)
    {
        parent::__construct($tokenizer);

        $this->packer = new TextPacker($sentences ?? new SentenceSplitter);
    }

    public function chunk(ParsedDocument $document, array $options = []): array
    {
        $pieces = $this->packer->pack(
            $document->text,
            [TextPacker::PARAGRAPH, TextPacker::SENTENCE, TextPacker::LINE, TextPacker::WORD],
            (int) $this->option($options, 'size', 1000),
            (int) $this->option($options, 'overlap', 200),
        );

        $chunks = [];
        foreach ($pieces as $index => $piece) {
            $chunks[] = $this->makeChunk($piece['text'], $index, $piece['offset'], $document->metadata, ['offset_unit' => 'char']);
        }

        return $chunks;
    }

    public function name(): string
    {
        return 'recursive';
    }
}
