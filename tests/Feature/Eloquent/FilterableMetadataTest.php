<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Sellinnate\RagEngine\Concerns\HasEmbeddings;
use Sellinnate\RagEngine\Contracts\Embeddable;
use Sellinnate\RagEngine\Contracts\Parser;
use Sellinnate\RagEngine\Contracts\VectorStore;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Eloquent\EmbeddableDefinition;
use Sellinnate\RagEngine\Embedding\EmbeddingService;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Managers\VectorStoreManager;
use Sellinnate\RagEngine\Models\Chunk;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Parsing\ParserManager;
use Sellinnate\RagEngine\Pipeline\IngestionPipeline;

/**
 * Filterable metadata declared on an Embeddable (or passed explicitly via
 * `rag_vector_metadata`) must reach every vector payload, and a metadata-only
 * change must refresh the stored vectors (access-control correctness).
 */
beforeEach(function () {
    BrainNote::$extra = [];
    Schema::dropIfExists('brain_notes');
    Schema::create('brain_notes', function ($t) {
        $t->increments('id');
        $t->string('title');
        $t->text('body');
        $t->string('scope')->default('internal');
        $t->json('tags')->nullable();
    });

    config()->set('rag-engine.eloquent.auto_sync', false);
    config()->set('rag-engine.eloquent.queue', false);
});

/**
 * @return list<array<string, mixed>>
 */
function storedPayloads(string $namespace = 'documents'): array
{
    /** @var VectorStore $store */
    $store = app(VectorStoreManager::class)->driver();
    $hits = $store->search($namespace, app(EmbeddingService::class)->embed(['x'])->vectorAt(0), new RetrievalQuery('x', topK: 1000));

    return array_map(static fn ($hit) => $hit->metadata, $hits);
}

it('propagates Embeddable::metadata() into every vector payload', function () {
    $note = BrainNote::create(['title' => 'NDA', 'body' => str_repeat('Confidential contract clause. ', 80), 'scope' => 'contracts', 'tags' => ['legal', 'nda']]);

    $note->syncEmbedding();

    $payloads = storedPayloads();
    expect($payloads)->not->toBeEmpty();

    foreach ($payloads as $payload) {
        expect($payload['scope'])->toBe('contracts')
            ->and($payload['priority'])->toBe(3)
            ->and($payload['tags'])->toBe(['legal', 'nda'])
            ->and($payload)->not->toHaveKey('nested')
            ->and($payload['embeddable_type'])->toBe(BrainNote::class)
            ->and($payload['embeddable_id'])->toBe((string) $note->id);
    }
});

it('lets a scope filter restrict search results', function () {
    BrainNote::create(['title' => 'Salary bands', 'body' => 'Quarterly salary bands for engineering.', 'scope' => 'hr'])->syncEmbedding();
    BrainNote::create(['title' => 'Onboarding', 'body' => 'Quarterly onboarding checklist for engineering.', 'scope' => 'internal'])->syncEmbedding();

    $hits = Rag::search('quarterly engineering')
        ->where('scope', ['in' => ['internal', 'contracts']])
        ->topK(10)
        ->get();

    expect($hits)->not->toBeEmpty();
    foreach ($hits as $hit) {
        expect($hit->metadata['scope'])->toBe('internal');
    }
});

it('never lets declared metadata shadow system keys', function () {
    $note = BrainNoteShadowing::create(['title' => 'x', 'body' => 'Shadow attempt body text.', 'scope' => 'internal']);

    $note->syncEmbedding();

    foreach (storedPayloads() as $payload) {
        expect($payload['tenant_id'])->toBe('default')
            ->and($payload['document_id'])->not->toBe('evil-doc')
            ->and($payload['chunk_id'])->not->toBe('evil-chunk')
            ->and($payload['embeddable_id'])->toBe((string) $note->id)
            ->and($payload['embeddable_type'])->toBe(BrainNoteShadowing::class)
            ->and($payload['is_parent'])->toBeFalse();
    }
});

it('keeps an explicit caller rag_vector_metadata on non-Eloquent sources', function () {
    $document = Rag::ingest(Rag::source()->text('Board minutes about the merger.', [
        'rag_vector_metadata' => ['scope' => 'board', 'tenant_id' => 'evil', 'chunk_id' => 'evil'],
    ]));
    app(IngestionPipeline::class)->process($document);

    $payloads = storedPayloads();
    expect($payloads)->not->toBeEmpty();
    foreach ($payloads as $payload) {
        expect($payload['scope'])->toBe('board')
            ->and($payload['tenant_id'])->toBe('default')
            ->and($payload['chunk_id'])->not->toBe('evil');
    }
});

it('re-indexes vectors when only the filterable metadata changes', function () {
    $note = BrainNote::create(['title' => 'Pricing', 'body' => 'Enterprise pricing sheet for the sales team.', 'scope' => 'internal']);
    $first = $note->syncEmbedding();

    $note->update(['scope' => 'contracts']);
    $second = $note->syncEmbedding();

    // Same logical document (content unchanged) …
    expect($second->id)->toBe($first->id)
        ->and($second->fresh()->status)->toBe('indexed')
        ->and($second->fresh()->metadata['rag_vector_metadata']['scope'])->toBe('contracts')
        ->and($second->fresh()->metadata['scope'])->toBe('contracts');

    // … but every stored vector now carries the new scope, none the stale one.
    $payloads = storedPayloads();
    expect($payloads)->not->toBeEmpty();
    foreach ($payloads as $payload) {
        expect($payload['scope'])->toBe('contracts');
    }

    expect(Rag::search('pricing sheet')->where('scope', 'internal')->get())->toBeEmpty();
});

it('drops metadata keys a model no longer declares', function () {
    BrainNote::$extra = ['region' => 'emea'];
    $note = BrainNote::create(['title' => 'x', 'body' => 'Declared keys change.', 'scope' => 'internal']);
    $document = $note->syncEmbedding();
    expect($document->fresh()->metadata['region'])->toBe('emea');

    BrainNote::$extra = [];
    $second = $note->syncEmbedding();

    $fresh = Document::query()->whereKey($document->id)->firstOrFail();
    expect($second->id)->toBe($document->id)
        ->and($fresh->metadata)->not->toHaveKey('region')
        ->and($fresh->metadata['rag_vector_metadata'])->not->toHaveKey('region')
        ->and($fresh->metadata)->toHaveKey('provenance')
        ->and($fresh->metadata['priority'])->toBe(3);

    foreach (storedPayloads() as $payload) {
        expect($payload)->not->toHaveKey('region');
    }
});

it('does not re-process when neither content nor metadata changed', function () {
    $note = BrainNote::create(['title' => 'Stable', 'body' => 'Nothing changes here.', 'scope' => 'internal']);
    $note->syncEmbedding();

    $chunkIds = Chunk::query()->pluck('id')->all();
    $note->syncEmbedding();

    expect(Chunk::query()->pluck('id')->all())->toBe($chunkIds);
});

it('refreshes vector metadata when identical text is re-ingested with a new scope', function () {
    $first = Rag::ingest(Rag::source()->text('Shared playbook text.', ['rag_vector_metadata' => ['scope' => 'internal']]));
    app(IngestionPipeline::class)->process($first);

    $second = Rag::ingest(Rag::source()->text('Shared playbook text.', ['rag_vector_metadata' => ['scope' => 'contracts']]));

    expect($second->id)->toBe($first->id)
        ->and($second->status)->toBe('pending')
        ->and($second->metadata['rag_vector_metadata'])->toBe(['scope' => 'contracts']);

    app(IngestionPipeline::class)->process($second);

    foreach (storedPayloads() as $payload) {
        expect($payload['scope'])->toBe('contracts');
    }
});

it('indexes two models with identical text as separate documents', function () {
    $a = BrainNote::create(['title' => 'Same', 'body' => 'Identical body.', 'scope' => 'internal']);
    $b = BrainNote::create(['title' => 'Same', 'body' => 'Identical body.', 'scope' => 'contracts']);

    $docA = $a->syncEmbedding();
    $docB = $b->syncEmbedding();

    expect($docB->id)->not->toBe($docA->id)
        ->and(Document::query()->whereNull('soft_deleted_at')->count())->toBe(2)
        ->and($docA->fresh()->metadata['embeddable_id'])->toBe((string) $a->id)
        ->and($docB->fresh()->metadata['embeddable_id'])->toBe((string) $b->id);

    $scopes = array_map(static fn (array $p) => $p['scope'].':'.$p['embeddable_id'], storedPayloads());
    sort($scopes);
    expect(array_values(array_unique($scopes)))->toBe(['contracts:'.$b->id, 'internal:'.$a->id]);
});

it('leaves the ingestion source metadata of a plain duplicate untouched', function () {
    $first = Rag::ingest(new IngestionSource('Same bytes.', 'text/plain', IngestionSource::TYPE_UPLOAD, ['filename' => 'a.txt']));
    $second = Rag::ingest(new IngestionSource('Same bytes.', 'text/plain', IngestionSource::TYPE_UPLOAD, ['filename' => 'b.txt']));

    expect($second->id)->toBe($first->id)
        ->and($second->metadata['filename'])->toBe('a.txt');
});

class BrainNote extends Model implements Embeddable
{
    use HasEmbeddings;

    protected $table = 'brain_notes';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['tags' => 'array'];

    /** @var array<string, mixed> */
    public static array $extra = [];

    public function toEmbeddable(): EmbeddableDefinition
    {
        return EmbeddableDefinition::make()
            ->add('Title', $this->title)
            ->add('Body', $this->body)
            ->metadata([
                'scope' => $this->scope,
                'priority' => 3,
                'tags' => $this->tags ?? [],
                'nested' => ['not' => 'filterable'],
                ...self::$extra,
            ]);
    }
}

class BrainNoteShadowing extends Model implements Embeddable
{
    use HasEmbeddings;

    protected $table = 'brain_notes';

    protected $guarded = [];

    public $timestamps = false;

    public function toEmbeddable(): EmbeddableDefinition
    {
        return EmbeddableDefinition::make()
            ->add('Body', $this->body)
            ->metadata([
                'scope' => $this->scope,
                'tenant_id' => 'other-tenant',
                'document_id' => 'evil-doc',
                'chunk_id' => 'evil-chunk',
                'embeddable_id' => '999999',
                'embeddable_type' => 'Evil',
                'is_parent' => true,
            ]);
    }
}

it('retries a keyed insert that loses a race to identical content under another key', function () {
    // Another key already holds these bytes; our first insert attempts lose the
    // (tenant, hash, version) race without a winner under our own key.
    Rag::ingest(Rag::source()->text('Raced content.', ['document_key' => 'theirs']));

    $failures = 2;
    Document::creating(function () use (&$failures) {
        if ($failures-- > 0) {
            throw new UniqueConstraintViolationException('testing', 'insert into rag_documents', [], new PDOException('duplicate'));
        }
    });

    $document = Rag::ingest(Rag::source()->text('Raced content.', ['document_key' => 'mine']));

    expect($failures)->toBe(-1)
        ->and($document->metadata['document_key'])->toBe('mine')
        ->and(Document::query()->count())->toBe(2);
});

it('gives up after repeated keyed races, and never retries un-keyed ones', function () {
    Document::creating(function () {
        throw new UniqueConstraintViolationException('testing', 'insert into rag_documents', [], new PDOException('duplicate'));
    });

    expect(fn () => Rag::ingest(Rag::source()->text('Always racing.', ['document_key' => 'k'])))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => Rag::ingest(Rag::source()->text('Un-keyed race.')))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('never lets parser metadata smuggle plaintext content into the payload', function () {
    app(ParserManager::class)->register(new class implements Parser
    {
        public function supports(string $mimeType): bool
        {
            return $mimeType === 'text/x-leaky';
        }

        public function parse(string $contents, string $mimeType, array $context = []): ParsedDocument
        {
            return new ParsedDocument($contents, 'text/plain', metadata: ['content' => 'LEAKED '.$contents, 'tenant_id' => 'evil']);
        }

        public function mimeTypes(): array
        {
            return ['text/x-leaky'];
        }
    });

    $document = Rag::ingest(new IngestionSource('Leaky parser body.', 'text/x-leaky', IngestionSource::TYPE_TEXT));
    app(IngestionPipeline::class)->process($document);

    foreach (storedPayloads() as $payload) {
        expect($payload)->not->toHaveKey('content')
            ->and($payload['tenant_id'])->toBe('default');
    }

    expect(Rag::search('leaky parser')->first()?->content)->toBe('Leaky parser body.');
});
