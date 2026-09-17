<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Chunking;

use Sellinnate\RagEngine\Contracts\Tokenizer;
use Sellinnate\RagEngine\Data\ParsedDocument;

/**
 * Sentence chunker (FR-CH-03): packs whole sentences into chunks of up to
 * `size` characters, so every chunk ends where a sentence ends.
 *
 * - Sentences come from {@see SentenceSplitter} (Italian/English aware:
 *   `S.r.l.`, `Dott.`, `ecc.`, `e.g.`, `€ 8.400,00`, `17.09.2026` and URLs do
 *   not end a sentence; blank lines always do).
 * - `overlap` is in characters, like every other strategy: the next chunk
 *   starts with as many whole trailing sentences of the previous one as fit.
 * - A single sentence longer than `size` (e.g. a table with no full stops) is
 *   split at line breaks, else between words (between characters for a word
 *   longer than `size`).
 * - Chunks are exact source slices; `offset` is a character offset.
 *
 * Unlike the recursive strategy it fills chunks sentence by sentence, even
 * across paragraph breaks, so chunks are denser but a paragraph that would fit
 * in one chunk may be split between two.
 */
final class SentenceChunker extends AbstractChunker
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
            [TextPacker::SENTENCE, TextPacker::LINE, TextPacker::WORD],
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
        return 'sentence';
    }
}
