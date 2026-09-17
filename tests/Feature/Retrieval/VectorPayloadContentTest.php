<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Data\VectorRecord;
use Sellinnate\RagEngine\Embedding\EmbeddingService;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Managers\VectorStoreManager;
use Sellinnate\RagEngine\Models\Chunk;
use Sellinnate\RagEngine\Pipeline\IngestionPipeline;
use Sellinnate\RagEngine\Retrieval\Retriever;

/**
 * With encryption enabled, chunk plaintext must not be written to the vector
 * store; retrieval hydrates hit content by decrypting the chunk rows instead.
 */
function ingestText(string $text, array $options = []): string
{
    $document = Rag::ingest(Rag::source()->text($text));
    app(IngestionPipeline::class)->process($document, $options);

    return (string) $document->id;
}

/**
 * @return list<SearchHit>
 */
function rawStoreHits(string $namespace = 'documents'): array
{
    $vector = app(EmbeddingService::class)->embed(['q'])->vectorAt(0);

    return app(VectorStoreManager::class)->driver()->search($namespace, $vector, new RetrievalQuery('q', topK: 100));
}

it('keeps plaintext out of the vector payload when encryption is enabled (default)', function () {
    ingestText('The launch codeword is aurora-borealis-7.');

    $hits = rawStoreHits();

    expect($hits)->not->toBeEmpty();
    foreach ($hits as $hit) {
        expect($hit->metadata)->not->toHaveKey('content')
            ->and($hit->content)->toBe('')
            ->and(json_encode($hit->metadata))->not->toContain('aurora');
    }
});

it('hydrates hit content from the encrypted chunk rows on retrieval', function () {
    ingestText('The launch codeword is aurora-borealis-7.');

    $hits = Rag::search('launch codeword')->topK(3)->get();

    expect($hits)->not->toBeEmpty()
        ->and($hits[0]->content)->toContain('aurora-borealis-7');
});

it('still stores plaintext when explicitly enabled', function () {
    config()->set('rag-engine.security.vector_payload_content', true);

    ingestText('Plain payload text for legacy stores.');

    foreach (rawStoreHits() as $hit) {
        expect($hit->content)->toContain('Plain payload text');
    }
});

it('stores plaintext by default when encryption is disabled', function () {
    config()->set('rag-engine.security.encryption_enabled', false);

    ingestText('Unencrypted deployment payload.');

    foreach (rawStoreHits() as $hit) {
        expect($hit->content)->toContain('Unencrypted deployment');
    }
});

it('omits plaintext when explicitly disabled even without encryption', function () {
    config()->set('rag-engine.security.encryption_enabled', false);
    config()->set('rag-engine.security.vector_payload_content', false);

    ingestText('Hidden anyway.');

    foreach (rawStoreHits() as $hit) {
        expect($hit->metadata)->not->toHaveKey('content');
    }

    expect(Rag::search('hidden')->first()?->content)->toContain('Hidden anyway');
});

it('keeps hybrid keyword scoring, dedup, rerank, MMR and budgets working on hydrated content', function () {
    config()->set('rag-engine.defaults.reranker', 'fake');

    ingestText("Zebra migration patterns across the savanna.\n\nCompletely unrelated paragraph about databases.");
    ingestText('Zebra migration patterns across the savanna.');

    $hits = Rag::search('zebra savanna')
        ->hybrid()
        ->dedup()
        ->rerank()
        ->mmr(0.7)
        ->contextBudget(500)
        ->topK(5)
        ->get();

    expect($hits)->not->toBeEmpty()
        ->and($hits[0]->content)->toContain('Zebra');

    $contents = array_map(static fn (SearchHit $h) => trim($h->content), $hits);
    expect(count($contents))->toBe(count(array_unique($contents)));
});

it('expands parents and answers questions with hydrated content', function () {
    config()->set('rag-engine.defaults.llm', 'fake');

    ingestText(str_repeat('Photosynthesis converts light into chemical energy. ', 30), ['parent_child' => true]);

    $hit = Rag::search('photosynthesis light energy')->expandParents()->first();

    expect($hit)->not->toBeNull()
        ->and($hit->content)->toContain('Photosynthesis')
        ->and($hit->metadata['parent_content'])->toContain('Photosynthesis');

    $answer = Rag::ask('What does photosynthesis convert?')->using('fake')->generate();
    expect($answer->sources)->not->toBeEmpty();
});

it('drops hits whose chunk row no longer exists (orphan vectors)', function () {
    ingestText('Orphan vector content.');
    Chunk::query()->delete();

    expect(Rag::search('orphan vector')->get())->toBe([]);
});

it('uses payload content when a legacy vector still carries it', function () {
    ingestText('Fresh encrypted chunk.');

    // Simulate a vector written by v1.2 (plaintext in the payload, no chunk row).
    $store = app(VectorStoreManager::class)->driver();
    $vector = app(EmbeddingService::class)->embed(['Legacy plaintext vector.'])->vectorAt(0);
    $store->upsert('documents', [new VectorRecord('legacy-1', $vector, [
        'tenant_id' => 'default',
        'document_id' => 'legacy-doc',
        'chunk_id' => 'legacy-1',
        'content' => 'Legacy plaintext vector.',
    ])]);

    $contents = array_map(static fn (SearchHit $h) => $h->content, Rag::search('legacy plaintext vector')->topK(5)->get());

    expect($contents)->toContain('Legacy plaintext vector.');
});

it('never hydrates content from another tenant', function () {
    Rag::forTenant('tenant-a', fn () => ingestText('Tenant A secret roadmap.'));

    // Copy tenant A's vector into tenant B's scope (a mis-scoped payload).
    $store = app(VectorStoreManager::class)->driver();
    $hit = Rag::forTenant('tenant-a', fn () => rawStoreHits()[0]);
    $store->upsert('documents', [new VectorRecord('copied', $hit->vector, [...$hit->metadata, 'tenant_id' => 'tenant-b'])]);

    $hits = Rag::forTenant('tenant-b', fn () => app(Retriever::class)->retrieve(Rag::search('secret roadmap')->toRequest()));

    expect($hits)->toBe([]);
});

it('hydrates from a plaintext chunk row and skips a malformed encrypted one', function () {
    ingestText('Alpha chunk text.');
    ingestText('Beta chunk text.');

    $chunks = Chunk::query()->orderBy('created_at')->get();
    $chunks[0]->forceFill(['encrypted_content' => null, 'content' => 'Alpha plaintext row.'])->save();
    $chunks[1]->forceFill(['encrypted_content' => '"not-an-object"'])->save();

    $contents = array_map(static fn (SearchHit $h) => $h->content, Rag::search('chunk text')->topK(5)->get());

    expect($contents)->toBe(['Alpha plaintext row.']);
});
