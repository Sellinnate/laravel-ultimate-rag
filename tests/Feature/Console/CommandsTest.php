<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sellinnate\RagEngine\Concerns\HasEmbeddings;
use Sellinnate\RagEngine\Contracts\Embeddable;
use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Eloquent\EmbeddableDefinition;
use Sellinnate\RagEngine\Embedding\EmbeddingService;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Managers\VectorStoreManager;
use Sellinnate\RagEngine\Models\ShreddedTenant;

it('rag:status reports document counts by status (FR-DX-05)', function () {
    Rag::forTenant('t1', fn () => Rag::ingest(new IngestionSource('a', 'text/plain', IngestionSource::TYPE_TEXT)));

    $this->artisan('rag:status', ['--tenant' => 't1'])
        ->assertSuccessful();
});

it('rag:stats shows usage for a tenant', function () {
    Rag::forTenant('t1', fn () => Rag::ingest(new IngestionSource('content here', 'text/plain', IngestionSource::TYPE_TEXT)));

    $this->artisan('rag:stats', ['tenant' => 't1'])->assertSuccessful();
});

it('rag:rotate-keys rotates a tenant key (FR-SEC-05)', function () {
    Rag::forTenant('t1', fn () => Rag::ingest(new IngestionSource('rotate this', 'text/plain', IngestionSource::TYPE_TEXT)));

    $this->artisan('rag:rotate-keys', ['tenant' => 't1'])
        ->expectsOutputToContain('Rotated key')
        ->assertSuccessful();
});

it('rag:purge crypto-shreds a tenant with --force (FR-SEC-04)', function () {
    Rag::forTenant('doomed', fn () => Rag::ingest(new IngestionSource('erase', 'text/plain', IngestionSource::TYPE_TEXT)));

    $this->artisan('rag:purge', ['tenant' => 'doomed', '--force' => true])->assertSuccessful();

    expect(ShreddedTenant::whereKey('doomed')->exists())->toBeTrue();
});

it('rag:purge aborts without --force when declined', function () {
    $this->artisan('rag:purge', ['tenant' => 't1'])
        ->expectsConfirmation('Permanently crypto-shred tenant [t1]? This is irreversible.', 'no')
        ->assertFailed();
});

it('rag:reconcile reports consistency (NFR-DR-02)', function () {
    $doc = Rag::forTenant('t1', fn () => Rag::ingest(new IngestionSource('reconcile me content', 'text/plain', IngestionSource::TYPE_TEXT)));
    Rag::forTenant('t1', fn () => Rag::process($doc));

    $this->artisan('rag:reconcile', ['tenant' => 't1'])->assertSuccessful();
});

it('rag:clear-cache clears the cache', function () {
    $this->artisan('rag:clear-cache')->assertSuccessful();
});

it('rag:reindex strips legacy plaintext and refreshes model metadata', function () {
    Schema::create('reindex_notes', function ($t) {
        $t->increments('id');
        $t->text('body');
        $t->string('scope');
    });
    config()->set('rag-engine.eloquent.auto_sync', false);

    // Index as v1.2 did: plaintext in the payload.
    config()->set('rag-engine.security.vector_payload_content', true);
    $text = Rag::forTenant('t9', fn () => Rag::ingest(new IngestionSource('Legacy plaintext document body.', 'text/plain', IngestionSource::TYPE_TEXT)));
    Rag::forTenant('t9', fn () => Rag::process($text));
    $note = ReindexNote::create(['body' => 'Model body text.', 'scope' => 'internal']);
    $gone = ReindexNote::create(['body' => 'Soon deleted.', 'scope' => 'internal']);
    Rag::forTenant('t9', function () use ($note, $gone) {
        $note->syncEmbedding();
        $gone->syncEmbedding();
    });
    // Metadata changed without a re-sync; one model row vanished.
    DB::table('reindex_notes')->where('id', $note->id)->update(['scope' => 'contracts']);
    DB::table('reindex_notes')->where('id', $gone->id)->delete();

    config()->set('rag-engine.security.vector_payload_content', null);

    $this->artisan('rag:reindex', ['tenant' => 't9'])
        ->expectsTable(['Result', 'Documents'], [['Re-indexed', 2], ['Removed (model gone)', 1], ['Failed', 0]])
        ->assertSuccessful();

    $store = app(VectorStoreManager::class)->driver();
    $vector = app(EmbeddingService::class)->embed(['q'])->vectorAt(0);
    $payloads = array_map(
        static fn ($hit) => $hit->metadata,
        $store->search('documents', $vector, new RetrievalQuery('q', topK: 100, tenantId: 't9')),
    );

    expect($payloads)->not->toBeEmpty();
    foreach ($payloads as $payload) {
        expect($payload)->not->toHaveKey('content')
            ->and($payload['embeddable_id'] ?? null)->not->toBe((string) $gone->id);
        if (isset($payload['embeddable_id'])) {
            expect($payload['scope'])->toBe('contracts');
        }
    }
});

it('rag:reindex reports failures and exits non-zero', function () {
    $doc = Rag::forTenant('t10', fn () => Rag::ingest(new IngestionSource('bad', 'application/x-unknown', IngestionSource::TYPE_TEXT)));

    $this->artisan('rag:reindex', ['tenant' => 't10'])
        ->expectsOutputToContain("Document [{$doc->id}]")
        ->assertFailed();
});

class ReindexNote extends Model implements Embeddable
{
    use HasEmbeddings;

    protected $table = 'reindex_notes';

    protected $guarded = [];

    public $timestamps = false;

    public function toEmbeddable(): EmbeddableDefinition
    {
        return EmbeddableDefinition::make()
            ->add('Body', $this->body)
            ->metadata(['scope' => $this->scope]);
    }
}
