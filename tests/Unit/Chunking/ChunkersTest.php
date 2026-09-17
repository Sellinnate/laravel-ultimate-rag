<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Chunking\FixedSizeChunker;
use Sellinnate\RagEngine\Chunking\MarkdownChunker;
use Sellinnate\RagEngine\Chunking\RecursiveCharacterChunker;
use Sellinnate\RagEngine\Chunking\SentenceChunker;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Tokenization\ApproximateTokenizer;

beforeEach(fn () => $this->tok = new ApproximateTokenizer);

function doc(string $text, array $metadata = []): ParsedDocument
{
    return new ParsedDocument($text, 'text/plain', metadata: $metadata);
}

it('FixedSizeChunker splits by characters with overlap (FR-CH-01)', function () {
    $text = str_repeat('abcdefghij', 30); // 300 chars
    $chunks = (new FixedSizeChunker($this->tok))->chunk(doc($text), ['size' => 100, 'overlap' => 20]);

    expect(count($chunks))->toBeGreaterThan(1)
        ->and(mb_strlen($chunks[0]->content))->toBe(100)
        ->and($chunks[0]->index)->toBe(0)
        ->and($chunks[1]->offset)->toBe(80) // step = size - overlap
        ->and($chunks[0]->tokenCount)->toBeGreaterThan(0);
});

it('FixedSizeChunker propagates document metadata and adds chunk metadata (FR-CH-09)', function () {
    $chunks = (new FixedSizeChunker($this->tok))->chunk(doc('hello world', ['source' => 'x']), ['size' => 100]);

    expect($chunks[0]->metadata['source'])->toBe('x')
        ->and($chunks[0]->metadata['chunk_index'])->toBe(0)
        ->and($chunks[0]->metadata)->toHaveKey('offset');
});

it('FixedSizeChunker token-aware mode respects a token budget (FR-CH-06)', function () {
    $text = str_repeat('word ', 200);
    $chunks = (new FixedSizeChunker($this->tok))->chunk(doc($text), ['size' => 20, 'overlap' => 5, 'unit' => 'tokens']);

    expect(count($chunks))->toBeGreaterThan(1);
    foreach ($chunks as $chunk) {
        expect($this->tok->count($chunk->content))->toBeLessThanOrEqual(21);
    }
});

it('FixedSizeChunker returns nothing for empty text', function () {
    expect((new FixedSizeChunker($this->tok))->chunk(doc('')))->toBe([])
        ->and((new FixedSizeChunker($this->tok))->name())->toBe('fixed');
});

it('RecursiveCharacterChunker keeps pieces under size and prefers paragraph breaks (FR-CH-02)', function () {
    $text = "Para one sentence.\n\nPara two is here.\n\nPara three ends.";
    $chunks = (new RecursiveCharacterChunker($this->tok))->chunk(doc($text), ['size' => 25, 'overlap' => 5]);

    expect(count($chunks))->toBeGreaterThan(1);
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk->content))->toBeLessThanOrEqual(25);
    }
    expect((new RecursiveCharacterChunker($this->tok))->name())->toBe('recursive');
});

it('RecursiveCharacterChunker returns nothing for blank text', function () {
    expect((new RecursiveCharacterChunker($this->tok))->chunk(doc('   ')))->toBe([]);
});

it('SentenceChunker never splits mid-sentence (FR-CH-03)', function () {
    $text = 'First sentence here. Second one follows! Third question? Fourth and last.';
    $chunks = (new SentenceChunker($this->tok))->chunk(doc($text), ['size' => 40, 'overlap' => 0]);

    expect(count($chunks))->toBeGreaterThan(1);
    foreach ($chunks as $chunk) {
        // Each chunk ends on a sentence terminator.
        expect(preg_match('/[.!?]$/', trim($chunk->content)))->toBe(1);
    }
    expect((new SentenceChunker($this->tok))->name())->toBe('sentence');
});

it('MarkdownChunker splits on headings and records the heading (FR-CH-04)', function () {
    $md = "# Intro\n\nIntro body text.\n\n## Details\n\nDetail body text here.";
    $chunks = (new MarkdownChunker($this->tok))->chunk(doc($md), ['size' => 1000]);

    expect(count($chunks))->toBe(2)
        ->and($chunks[0]->metadata['heading'])->toBe('Intro')
        ->and($chunks[0]->metadata['offset_unit'])->toBe('char')
        ->and($chunks[1]->metadata['heading'])->toBe('Details')
        ->and($chunks[0]->content)->toContain('Intro body')
        ->and((new MarkdownChunker($this->tok))->name())->toBe('markdown');
});

it('MarkdownChunker sub-splits oversized sections keeping the heading', function () {
    $md = "# Big\n\n".str_repeat('sentence words here. ', 50);
    $chunks = (new MarkdownChunker($this->tok))->chunk(doc($md), ['size' => 80, 'overlap' => 10]);

    expect(count($chunks))->toBeGreaterThan(1);
    foreach ($chunks as $chunk) {
        expect($chunk->metadata['heading'])->toBe('Big');
    }
});

it('MarkdownChunker offsets anchor each chunk in the source, in UTF-8', function () {
    $md = "Intro è breve.\n\n# Perché\n\nCorpo della sezione.\n\n## Così\n\n".str_repeat('Frase lunga qui. ', 8);
    $previous = mb_internal_encoding();
    mb_internal_encoding('ISO-8859-1');

    try {
        $chunks = (new MarkdownChunker($this->tok))->chunk(doc($md), ['size' => 60, 'overlap' => 0]);
    } finally {
        mb_internal_encoding($previous);
    }

    expect(count($chunks))->toBeGreaterThan(3);
    $last = -1;
    foreach ($chunks as $chunk) {
        $firstLine = explode("\n", $chunk->content)[0];
        expect(mb_substr($md, $chunk->offset, mb_strlen($firstLine)))->toBe($firstLine)
            ->and($chunk->offset)->toBeGreaterThan($last);
        $last = $chunk->offset;
    }
    expect($chunks[1]->offset)->toBe(mb_strpos($md, 'Perché'));
});
