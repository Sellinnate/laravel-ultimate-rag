<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Chunking\ContextualHeaderEnricher;
use Sellinnate\RagEngine\Chunking\FixedSizeChunker;
use Sellinnate\RagEngine\Chunking\ParentChildChunker;
use Sellinnate\RagEngine\Chunking\RecursiveCharacterChunker;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Data\TextChunk;
use Sellinnate\RagEngine\Tokenization\ApproximateTokenizer;

beforeEach(fn () => $this->tok = new ApproximateTokenizer);

it('ParentChildChunker returns parents once and links children by index (FR-CH-07, H3)', function () {
    $text = str_repeat('alpha beta gamma delta. ', 60);
    $chunker = new ParentChildChunker(
        new RecursiveCharacterChunker($this->tok),
        new FixedSizeChunker($this->tok),
    );

    $chunks = $chunker->chunk(new ParsedDocument($text, 'text/plain'), [
        'parent_size' => 400,
        'child_size' => 100,
        'child_overlap' => 10,
    ]);

    $parents = array_values(array_filter($chunks, fn (TextChunk $c) => $c->metadata['is_parent'] === true));
    $children = array_values(array_filter($chunks, fn (TextChunk $c) => $c->metadata['is_parent'] === false));

    expect(count($parents))->toBeGreaterThan(0)
        ->and(count($children))->toBeGreaterThan(1)
        ->and($chunker->name())->toBe('parent-child');

    // No child duplicates the parent text in metadata (memory blow-up fixed).
    foreach ($children as $child) {
        expect($child->metadata)->not->toHaveKey('parent_content')
            ->and($child->parentIndex)->not->toBeNull();
        // The referenced parent exists in the list at that index.
        expect($chunks[$child->parentIndex]->metadata['is_parent'])->toBeTrue();
    }
});

it('ContextualHeaderEnricher prepends document + section context (FR-CH-08)', function () {
    $doc = new ParsedDocument('body', 'text/plain', metadata: ['title' => 'My Doc']);
    $chunks = [
        new TextChunk('child a', 0, metadata: ['heading' => 'Intro']),
        new TextChunk('child b', 1),
    ];

    $enriched = (new ContextualHeaderEnricher)->enrich($chunks, $doc);

    expect($enriched[0]->contextHeader)->toBe('Document: My Doc > Section: Intro')
        ->and($enriched[0]->embeddableText())->toContain('Document: My Doc')
        ->and($enriched[0]->embeddableText())->toContain('child a')
        ->and($enriched[1]->contextHeader)->toBe('Document: My Doc');
});

it('ContextualHeaderEnricher is a no-op without a title or heading', function () {
    $doc = new ParsedDocument('body', 'text/plain');
    $chunks = [new TextChunk('x', 0)];

    expect((new ContextualHeaderEnricher)->enrich($chunks, $doc)[0]->contextHeader)->toBeNull();
});

it('ContextualHeaderEnricher stores the header as chunk metadata and normalises the title', function () {
    $doc = new ParsedDocument('body', 'text/plain', metadata: ['title' => "  Preventivo\n  Livio   Cheese ", 'filename' => 'q.pdf']);

    $chunk = (new ContextualHeaderEnricher)->enrich([new TextChunk('child', 0)], $doc)[0];

    expect($chunk->contextHeader)->toBe('Document: Preventivo Livio Cheese')
        ->and($chunk->metadata[ContextualHeaderEnricher::METADATA_KEY])->toBe('Document: Preventivo Livio Cheese')
        ->and($chunk->content)->toBe('child')
        ->and($chunk->embeddableText())->toBe("Document: Preventivo Livio Cheese\n\nchild");
});

it('ContextualHeaderEnricher falls back to the filename when the title is blank', function () {
    $doc = new ParsedDocument('body', 'text/plain', metadata: ['title' => '   ', 'filename' => 'preventivo.pdf']);

    expect((new ContextualHeaderEnricher)->enrich([new TextChunk('x', 0)], $doc)[0]->contextHeader)
        ->toBe('Document: preventivo.pdf');
});
