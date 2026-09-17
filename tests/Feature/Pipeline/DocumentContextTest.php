<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Sellinnate\RagEngine\Chunking\ContextualHeaderEnricher;
use Sellinnate\RagEngine\Concerns\HasEmbeddings;
use Sellinnate\RagEngine\Contracts\Embeddable;
use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Contracts\Parser;
use Sellinnate\RagEngine\Data\EmbeddingResponse;
use Sellinnate\RagEngine\Data\EncryptedPayload;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Eloquent\EmbeddableDefinition;
use Sellinnate\RagEngine\Embedding\FakeEmbedder;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Managers\EmbedderManager;
use Sellinnate\RagEngine\Models\Chunk;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Parsing\ParserManager;
use Sellinnate\RagEngine\Retrieval\KeywordScorer;
use Sellinnate\RagEngine\Security\EnvelopeEncrypter;
use Sellinnate\RagEngine\Tests\Support\PdfBuilder;

/** Records every text sent for embedding (queries included). */
final class RecordingEmbedder implements Embedder
{
    /** @var list<string> */
    public static array $texts = [];

    private FakeEmbedder $inner;

    public function __construct()
    {
        $this->inner = new FakeEmbedder;
    }

    public function embed(array $texts): EmbeddingResponse
    {
        array_push(self::$texts, ...$texts);

        return $this->inner->embed($texts);
    }

    public function embedOne(string $text): EmbeddingResponse
    {
        return $this->embed([$text]);
    }

    public function dimensions(): int
    {
        return $this->inner->dimensions();
    }

    public function model(): string
    {
        return $this->inner->model();
    }
}

beforeEach(function () {
    Schema::dropIfExists('quotes');
    Schema::create('quotes', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->string('category')->nullable();
        $t->string('path')->nullable();
        $t->string('meta_title')->nullable();
    });

    config()->set('rag-engine.eloquent.auto_sync', false);
    config()->set('rag-engine.chunking.chunk_size', 300);
    config()->set('rag-engine.chunking.chunk_overlap', 80);
    config()->set('rag-engine.embedders.recording', ['driver' => 'recording']);
    config()->set('rag-engine.defaults.embedder', 'recording');
    app(EmbedderManager::class)->extend('recording', fn (): Embedder => new RecordingEmbedder);
    RecordingEmbedder::$texts = [];

    $footer = "Sellinnate S.r.l. | Viale Belfiore 55, 50144 Firenze | P.IVA: 12345678901\t";
    $this->pdfPath = tempnam(sys_get_temp_dir(), 'quote').'.pdf';
    file_put_contents($this->pdfPath, PdfBuilder::make()
        ->page([
            'Preventivo — Gestionale per la distribuzione alimentare',
            'Destinatario: Livio Cheese S.r.l., Via Daverio 6, Milano.',
            'Contesto e obiettivo',
            'Il cliente seleziona e distribuisce specialità casearie a ristoranti, gastronomie e',
            'negozi specializzati. Ogni giorno il lavoro parte dall\'ordine del cliente e arriva alla',
            'consegna e alla fattura, passando per acquisti, magazzino e preparazione della merce.',
        ], $footer.'1')
        ->page([
            'Voci di costo',
            'Licenza d\'uso della piattaforma con tutti i moduli richiesti dal cliente.',
            'Il costo della licenza è di € 3.900,00 e la configurazione costa € 1.200,00.',
            'Le personalizzazioni sul flusso costano € 1.800,00 e l\'integrazione € 700,00.',
            'La formazione del team e la messa in produzione costano € 800,00.',
            'Totale imponibile € 8.400,00 più IVA al 22%, per un totale di € 10.248,00.',
        ], $footer.'2')
        ->page([
            'Tempi e modalità di pagamento',
            'Il pagamento è suddiviso in due tranche da € 4.200,00 ciascuna, alla conferma',
            'e al collaudo. Bonifico bancario a 30 giorni data fattura.',
        ], $footer.'3')
        ->build());
});

afterEach(fn () => @unlink($this->pdfPath));

/**
 * @return list<Chunk>
 */
function childChunks(Document $document): array
{
    return Chunk::query()->where('document_id', $document->id)->orderBy('position')->get()->all();
}

function chunkText(Chunk $chunk): string
{
    $payload = json_decode((string) $chunk->encrypted_content, true);

    return app(EnvelopeEncrypter::class)->decrypt(EncryptedPayload::fromArray($payload));
}

it('heads every chunk of a model document with its declared title', function () {
    $quote = Quote::create(['name' => 'Preventivo Livio Cheese', 'category' => 'Quote', 'path' => $this->pdfPath]);
    $document = $quote->syncEmbedding();

    expect($document->metadata['title'])->toBe('Preventivo Livio Cheese');

    $chunks = childChunks($document);
    expect(count($chunks))->toBeGreaterThan(2);

    $priceChunk = null;
    foreach ($chunks as $chunk) {
        $content = chunkText($chunk);

        // The header is metadata, not part of the stored/returned chunk text.
        expect($chunk->metadata[ContextualHeaderEnricher::METADATA_KEY])->toBe('Document: Preventivo Livio Cheese')
            ->and($content)->not->toContain('Document: Preventivo')
            ->and($content)->not->toContain('Viale Belfiore');

        if (str_contains($content, '8.400,00')) {
            $priceChunk = $content;
        }
    }

    // The chunk holding the prices never names the client…
    expect($priceChunk)->not->toBeNull()
        ->and($priceChunk)->not->toContain('Livio Cheese');

    // …but what was embedded for it does, through the header.
    $embedded = array_values(array_filter(RecordingEmbedder::$texts, fn (string $t) => str_contains($t, '8.400,00')));
    expect($embedded)->not->toBeEmpty();
    foreach ($embedded as $text) {
        expect($text)->toStartWith("Document: Preventivo Livio Cheese\n\n");
    }
});

it('keeps labels and values of the compiled model on their own lines', function () {
    $quote = Quote::create(['name' => 'Preventivo Livio Cheese', 'category' => 'Quote', 'path' => $this->pdfPath]);
    $document = $quote->syncEmbedding();

    $first = chunkText(childChunks($document)[0]);

    expect($first)->toStartWith("[Document]\nPreventivo Livio Cheese\n\n[Category]\nQuote\n\n[File]\nPreventivo — Gestionale")
        ->and($first)->not->toContain('Quote [File]');
});

it('returns hits without the header but ranks them by it in hybrid search', function () {
    Quote::create(['name' => 'Preventivo Livio Cheese', 'path' => $this->pdfPath])->syncEmbedding();

    $hits = Rag::search('quanto costa il preventivo Livio Cheese')->topK(20)->get();
    expect($hits)->not->toBeEmpty();

    $priceHit = collect($hits)->first(fn (SearchHit $hit) => str_contains($hit->content, '8.400,00'));
    expect($priceHit)->not->toBeNull()
        ->and($priceHit->content)->not->toContain('Document:')
        ->and($priceHit->metadata['context_header'])->toBe('Document: Preventivo Livio Cheese')
        ->and($priceHit->metadata['title'])->toBe('Preventivo Livio Cheese');

    // The BM25 half of hybrid search matches the client name through the header.
    $ranked = (new KeywordScorer)->rank('Livio Cheese', [$priceHit->withScore(0.0)], 5);
    expect($ranked)->toHaveCount(1)
        ->and((new KeywordScorer)->rank('Livio Cheese', [new SearchHit('x', 0.0, $priceHit->content)], 5))->toBe([]);

    $hybrid = Rag::search('Livio Cheese 8.400,00')->hybrid()->topK(20)->get();
    expect(collect($hybrid)->contains(fn (SearchHit $hit) => str_contains($hit->content, '8.400,00')))->toBeTrue();
});

it('honours a title passed through metadata() and lets title() win over it', function () {
    $fromMetadata = Quote::create(['name' => 'Ignored', 'meta_title' => 'Titolo da metadata', 'path' => $this->pdfPath]);
    $document = $fromMetadata->syncEmbedding();

    expect($document->metadata['title'])->toBe('Titolo da metadata')
        ->and(childChunks($document)[0]->metadata['context_header'])->toBe('Document: Titolo da metadata');

    $both = TitledQuote::create(['name' => "Titolo   dichiarato\n", 'meta_title' => 'Titolo da metadata', 'path' => $this->pdfPath]);
    $document = $both->syncEmbedding();

    expect($document->metadata['title'])->toBe('Titolo dichiarato')
        ->and($document->metadata['rag_vector_metadata']['title'])->toBe('Titolo dichiarato')
        ->and(childChunks($document)[0]->metadata['context_header'])->toBe('Document: Titolo dichiarato');
});

it('re-indexes a model whose title changed but whose text did not', function () {
    $quote = TitledQuote::create(['name' => 'Titolo iniziale', 'path' => $this->pdfPath]);
    $first = $quote->syncEmbedding();

    // Same compiled text (the name is only the title), different title.
    $quote->update(['name' => 'Titolo aggiornato']);
    $second = $quote->syncEmbedding();

    expect($second->id)->toBe($first->id)
        ->and($second->status)->toBe('indexed')
        ->and($second->metadata['title'])->toBe('Titolo aggiornato');

    foreach (childChunks($second) as $chunk) {
        expect($chunk->metadata['context_header'])->toBe('Document: Titolo aggiornato');
    }

    // Unchanged title: no re-processing.
    RecordingEmbedder::$texts = [];
    $quote->syncEmbedding();
    expect(RecordingEmbedder::$texts)->toBe([]);
});

it('uses the title and filename of plain ingested documents, and re-indexes on a new title', function () {
    $pdf = file_get_contents($this->pdfPath);

    $byFilename = Rag::ingest(new IngestionSource($pdf, 'application/pdf', IngestionSource::TYPE_UPLOAD, ['filename' => 'preventivo.pdf']));
    Rag::process($byFilename);
    expect(childChunks($byFilename)[0]->metadata['context_header'])->toBe('Document: preventivo.pdf');

    $text = 'Il totale del preventivo è € 8.400,00 IVA esclusa.';
    $document = Rag::ingest(Rag::source()->text($text), ['title' => 'Preventivo A']);
    Rag::process($document);
    expect(childChunks($document)[0]->metadata['context_header'])->toBe('Document: Preventivo A');

    // Same bytes, new title: the document is flagged for re-processing.
    $again = Rag::ingest(Rag::source()->text($text), ['title' => 'Preventivo B']);
    expect($again->id)->toBe($document->id)
        ->and($again->status)->toBe('pending')
        ->and($again->metadata['title'])->toBe('Preventivo B');

    Rag::process($again);
    expect(childChunks($again)[0]->metadata['context_header'])->toBe('Document: Preventivo B');

    // Same bytes, same title, no other indexed keys: untouched.
    $unchanged = Rag::ingest(Rag::source()->text($text), ['title' => 'Preventivo B']);
    expect($unchanged->status)->toBe('indexed');
    $noTitle = Rag::ingest(Rag::source()->text($text), ['note' => 'ignored']);
    expect($noTitle->status)->toBe('indexed')
        ->and($noTitle->metadata)->not->toHaveKey('note');
});

it('lets a declared title beat the title stored inside the PDF', function () {
    $pdf = fn (string $body) => PdfBuilder::make()->title('Microsoft Word - doc1.docx')->page([$body])->build();

    $native = Rag::ingest(new IngestionSource($pdf('Primo documento.'), 'application/pdf', IngestionSource::TYPE_UPLOAD, ['filename' => 'a.pdf']));
    Rag::process($native);
    $declared = Rag::ingest(new IngestionSource($pdf('Secondo documento.'), 'application/pdf', IngestionSource::TYPE_UPLOAD, ['filename' => 'b.pdf', 'title' => 'Contratto quadro']));
    Rag::process($declared);

    expect(childChunks($native)[0]->metadata['context_header'])->toBe('Document: Microsoft Word - doc1.docx')
        ->and(childChunks($declared)[0]->metadata['context_header'])->toBe('Document: Contratto quadro');
});

it('redacts PII inside the title before it reaches the header', function () {
    $document = Rag::ingest(Rag::source()->text('Corpo del documento.'), ['title' => 'Offerta per mario.rossi@example.com']);
    Rag::process($document);

    $header = childChunks($document)[0]->metadata['context_header'];
    expect($header)->toBe('Document: Offerta per [EMAIL]');
    expect(implode(' ', RecordingEmbedder::$texts))->not->toContain('mario.rossi@example.com');
});

it('builds headers for existing documents on rag:reindex', function () {
    // Indexed as before v1.4: no contextual header was produced.
    config()->set('rag-engine.chunking.contextual_headers', false);
    $quote = TitledQuote::create(['name' => 'Preventivo Livio Cheese', 'path' => $this->pdfPath]);
    Rag::forTenant('t-ctx', fn () => $quote->syncEmbedding());
    $document = Document::query()->where('tenant_id', 't-ctx')->firstOrFail();
    expect(childChunks($document)[0]->metadata)->not->toHaveKey('context_header');

    config()->set('rag-engine.chunking.contextual_headers', true);
    $this->artisan('rag:reindex', ['tenant' => 't-ctx'])->assertSuccessful();

    $document = Document::query()->where('tenant_id', 't-ctx')->whereNull('soft_deleted_at')->firstOrFail();
    expect(childChunks($document)[0]->metadata['context_header'])->toBe('Document: Preventivo Livio Cheese');
});

it('falls back to the document filename when a custom parser drops it', function () {
    app(ParserManager::class)->register(new class implements Parser
    {
        public function supports(string $mimeType): bool
        {
            return $mimeType === 'text/x-custom';
        }

        public function parse(string $contents, string $mimeType, array $context = []): ParsedDocument
        {
            return new ParsedDocument($contents, $mimeType);
        }

        public function mimeTypes(): array
        {
            return ['text/x-custom'];
        }
    });

    $document = Rag::ingest(new IngestionSource('Contenuto personalizzato.', 'text/x-custom', IngestionSource::TYPE_UPLOAD, ['filename' => 'custom.txt']));
    Rag::process($document);

    expect(childChunks($document)[0]->metadata)->toMatchArray([
        'filename' => 'custom.txt',
        'context_header' => 'Document: custom.txt',
    ]);
});

it('can turn contextual headers off with an env-backed setting', function () {
    config()->set('rag-engine.chunking.contextual_headers', false);
    $document = Rag::ingest(Rag::source()->text('Corpo.'), ['title' => 'Titolo']);
    Rag::process($document);

    expect(childChunks($document)[0]->metadata)->not->toHaveKey('context_header')
        ->and(RecordingEmbedder::$texts)->toContain('Corpo.');
});

class Quote extends Model implements Embeddable
{
    use HasEmbeddings;

    protected $table = 'quotes';

    protected $guarded = [];

    public $timestamps = false;

    public function toEmbeddable(): EmbeddableDefinition
    {
        return EmbeddableDefinition::make()
            ->add('Document', $this->meta_title === null ? $this->name : null)
            ->add('Category', $this->category)
            ->addFile('File', $this->path, null, 'application/pdf')
            ->metadata(array_filter([
                'scope' => 'sales',
                'title' => $this->meta_title ?? $this->name,
            ]));
    }
}

class TitledQuote extends Model implements Embeddable
{
    use HasEmbeddings;

    protected $table = 'quotes';

    protected $guarded = [];

    public $timestamps = false;

    public function toEmbeddable(): EmbeddableDefinition
    {
        return EmbeddableDefinition::make()
            ->title($this->name)
            ->addFile('File', $this->path, null, 'application/pdf')
            ->metadata(array_filter(['title' => $this->meta_title]));
    }
}
