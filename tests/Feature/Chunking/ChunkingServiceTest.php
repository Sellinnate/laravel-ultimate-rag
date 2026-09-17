<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Chunking\ChunkingService;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Managers\ChunkerManager;

beforeEach(fn () => $this->service = app(ChunkingService::class));

it('chunks using the default strategy with contextual headers', function () {
    $doc = new ParsedDocument(str_repeat('some words here. ', 200), 'text/plain', metadata: ['title' => 'Doc']);

    $chunks = $this->service->chunk($doc);

    expect(count($chunks))->toBeGreaterThan(1)
        ->and($chunks[0]->contextHeader)->toBe('Document: Doc');
});

it('honours an explicit strategy option', function () {
    $doc = new ParsedDocument("# H\n\nbody one.\n\n## H2\n\nbody two.", 'text/plain');

    $chunks = $this->service->chunk($doc, ['strategy' => 'markdown']);

    expect($chunks[0]->metadata['heading'])->toBe('H');
});

it('produces parent-child chunks when enabled', function () {
    $doc = new ParsedDocument(str_repeat('alpha beta gamma delta. ', 80), 'text/plain');

    $chunks = $this->service->chunk($doc, ['parent_child' => true, 'child_size' => 100]);

    $parents = array_filter($chunks, fn ($c) => ($c->metadata['is_parent'] ?? null) === true);
    $children = array_filter($chunks, fn ($c) => ($c->metadata['is_parent'] ?? null) === false);

    expect($parents)->not->toBeEmpty()
        ->and($children)->not->toBeEmpty();
});

it('can disable contextual headers via config', function () {
    config()->set('rag-engine.chunking.contextual_headers', false);
    $doc = new ParsedDocument('some text here to chunk into pieces', 'text/plain', metadata: ['title' => 'Doc']);

    expect($this->service->chunk($doc)[0]->contextHeader)->toBeNull();
});

it('resolves chunkers through the manager (FR-CH-10)', function () {
    expect(app(ChunkerManager::class)->driver('sentence')->name())->toBe('sentence');
});

it('passes configured abbreviations to the sentence-aware chunkers', function () {
    config()->set('rag-engine.chunking.abbreviations', ['Rep', 42]);
    config()->set('rag-engine.chunkers.sentence', ['driver' => 'sentence', 'abbreviations' => ['Cfm.']]);
    $manager = app(ChunkerManager::class)->forgetDrivers();

    $contents = fn (string $driver, string $text): array => array_map(
        fn ($chunk) => $chunk->content,
        $manager->driver($driver)->chunk(new ParsedDocument($text, 'text/plain'), ['size' => 20, 'overlap' => 0]),
    );

    // A known abbreviation keeps the 30-char sentence whole, so it is cut
    // between words; otherwise it is two sentences that each fit.
    foreach (['sentence', 'recursive', 'markdown'] as $driver) {
        expect($contents($driver, 'Vedi Rep. Allegato numero uno.'))->toBe(['Vedi Rep. Allegato', 'numero uno.']);
    }

    // Per-connection abbreviations only apply to that connection.
    expect($contents('sentence', 'Vedi Cfm. Allegato numero due.'))->toBe(['Vedi Cfm. Allegato', 'numero due.'])
        ->and($contents('recursive', 'Vedi Cfm. Allegato numero due.'))->toBe(['Vedi Cfm.', 'Allegato numero due.']);
});

it('reads chunk size and overlap from config', function () {
    config()->set('rag-engine.chunking.chunk_size', 41);
    config()->set('rag-engine.chunking.chunk_overlap', 0);

    $chunks = $this->service->chunk(new ParsedDocument(str_repeat('Una frase breve qui. ', 10), 'text/plain'));

    expect($chunks)->toHaveCount(5);
    foreach ($chunks as $chunk) {
        expect($chunk->content)->toBe('Una frase breve qui. Una frase breve qui.');
    }
});
