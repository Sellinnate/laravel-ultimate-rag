<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sellinnate\RagEngine\Concerns\HasEmbeddings;
use Sellinnate\RagEngine\Contracts\Embeddable;
use Sellinnate\RagEngine\Eloquent\EmbeddableDefinition;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Models\Chunk;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Models\EmbeddingRecord;
use Sellinnate\RagEngine\Pipeline\IngestionPipeline;
use Sellinnate\RagEngine\Recovery\Reconciler;
use Sellinnate\RagEngine\Security\CryptoShredder;

/**
 * Embedding bookkeeping rows must never outlive their chunks: re-syncs,
 * forgets, purges and re-indexes all keep `rag:reconcile` consistent.
 */
beforeEach(function () {
    Schema::dropIfExists('lifecycle_notes');
    Schema::create('lifecycle_notes', function ($t) {
        $t->increments('id');
        $t->text('body');
        $t->string('custom_key')->nullable();
    });
    config()->set('rag-engine.eloquent.auto_sync', false);
    config()->set('rag-engine.eloquent.queue', false);
});

it('stays consistent when a model is re-synced with new content and then forgotten', function () {
    $note = LifecycleNote::create(['body' => 'First generation text.']);
    $note->syncEmbedding();

    $note->update(['body' => 'Second generation text, different.']);
    $note->syncEmbedding();

    $reconciler = app(Reconciler::class);
    expect($reconciler->reconcile('default'))->toBe(['missing_embeddings' => [], 'orphan_embeddings' => []])
        ->and(EmbeddingRecord::query()->count())->toBe(Chunk::query()->count());

    $note->forgetEmbedding();

    expect($reconciler->isConsistent('default'))->toBeTrue()
        ->and(EmbeddingRecord::query()->count())->toBe(0)
        ->and(Document::query()->count())->toBe(0);
});

it('removes embedding records on purge and keeps other tenants untouched', function () {
    $mine = Rag::ingest(new IngestionSource('Purge me please.', 'text/plain', IngestionSource::TYPE_TEXT));
    app(IngestionPipeline::class)->process($mine);
    Rag::forTenant('other', function () {
        $document = Rag::ingest(new IngestionSource('Keep me around.', 'text/plain', IngestionSource::TYPE_TEXT));
        app(IngestionPipeline::class)->process($document);
    });

    Rag::ingestor()->purge($mine);

    expect(EmbeddingRecord::query()->where('tenant_id', 'default')->count())->toBe(0)
        ->and(EmbeddingRecord::query()->where('tenant_id', 'other')->count())->toBeGreaterThan(0)
        ->and(app(Reconciler::class)->isConsistent('default'))->toBeTrue()
        ->and(app(Reconciler::class)->isConsistent('other'))->toBeTrue();
});

it('stays consistent across re-indexing and crypto-shredding', function () {
    $document = Rag::ingest(new IngestionSource(str_repeat('Reindex me. ', 200), 'text/plain', IngestionSource::TYPE_TEXT));
    app(IngestionPipeline::class)->process($document);
    app(IngestionPipeline::class)->process($document->fresh(), ['strategy' => 'sentence']);

    expect(app(Reconciler::class)->isConsistent('default'))->toBeTrue();

    app(CryptoShredder::class)->shredTenant('default');

    expect(EmbeddingRecord::query()->count())->toBe(0);
});

it('rag:reconcile --prune deletes pre-existing orphan records of that tenant only', function () {
    $document = Rag::forTenant('t-prune', function () {
        $document = Rag::ingest(new IngestionSource('Legacy orphan source.', 'text/plain', IngestionSource::TYPE_TEXT));
        app(IngestionPipeline::class)->process($document);

        return $document;
    });
    // Simulate the v1.2 purge behaviour: chunks gone, embedding records left behind.
    Chunk::query()->where('document_id', $document->id)->delete();
    $leftBehind = EmbeddingRecord::query()->where('tenant_id', 't-prune')->count();
    EmbeddingRecord::query()->create([
        'chunk_id' => 'other-tenant-chunk', 'tenant_id' => 'someone-else', 'model' => 'm', 'dimensions' => 8, 'provider' => 'p',
    ]);

    expect($leftBehind)->toBeGreaterThan(0);

    $this->artisan('rag:reconcile', ['tenant' => 't-prune'])->assertFailed();

    $this->artisan('rag:reconcile', ['tenant' => 't-prune', '--prune' => true])
        ->expectsOutputToContain("Pruned {$leftBehind} orphan embedding record(s).")
        ->assertSuccessful();

    expect(EmbeddingRecord::query()->where('tenant_id', 'someone-else')->count())->toBe(1);
});

it('forgets and reindex-removes models that use a custom document key', function () {
    $note = LifecycleNote::create(['body' => 'Custom keyed note.', 'custom_key' => 'kb/custom-1']);
    $note->syncEmbedding();
    expect(Document::query()->firstOrFail()->metadata['document_key'])->toBe('kb/custom-1');

    $note->forgetEmbedding();
    expect(Document::query()->count())->toBe(0);

    $gone = LifecycleNote::create(['body' => 'Custom keyed, then deleted.', 'custom_key' => 'kb/custom-2']);
    $gone->syncEmbedding();
    DB::table('lifecycle_notes')->where('id', $gone->id)->delete();

    $this->artisan('rag:reindex', ['tenant' => 'default'])
        ->expectsTable(['Result', 'Documents'], [['Re-indexed', 0], ['Removed (model gone)', 1], ['Failed', 0]])
        ->assertSuccessful();

    expect(Document::query()->count())->toBe(0)
        ->and(EmbeddingRecord::query()->count())->toBe(0);
});

class LifecycleNote extends Model implements Embeddable
{
    use HasEmbeddings;

    protected $table = 'lifecycle_notes';

    protected $guarded = [];

    public $timestamps = false;

    public function toEmbeddable(): EmbeddableDefinition
    {
        $definition = EmbeddableDefinition::make()->add('Body', $this->body);

        return $this->custom_key !== null ? $definition->documentKey($this->custom_key) : $definition;
    }
}
