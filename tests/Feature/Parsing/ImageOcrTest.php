<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Sellinnate\RagEngine\Contracts\Ocr;
use Sellinnate\RagEngine\Eloquent\EmbeddableFileResolver;
use Sellinnate\RagEngine\Exceptions\ParsingException;
use Sellinnate\RagEngine\Exceptions\UnsupportedFileException;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Managers\OcrManager;
use Sellinnate\RagEngine\Ocr\NullOcr;
use Sellinnate\RagEngine\Parsing\ImageParser;
use Sellinnate\RagEngine\Parsing\ParserManager;
use Sellinnate\RagEngine\Pipeline\IngestionPipeline;

/**
 * Images (PNG/JPEG/WebP/TIFF) are routed to the configured OCR engine; with the
 * default `null` engine they keep the existing "unsupported" behaviour.
 */
final class VisionTestOcr implements Ocr
{
    /** @var list<string> */
    public array $seen = [];

    public function __construct(private readonly string $text = 'Invoice total 1200 EUR') {}

    public function ocr(string $contents, string $mimeType): string
    {
        $this->seen[] = $mimeType;

        return $this->text;
    }

    public function supports(string $mimeType): bool
    {
        return str_starts_with(strtolower($mimeType), 'image/');
    }

    public function name(): string
    {
        return 'vision-test';
    }
}

function pngBytes(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
}

function useVisionOcr(VisionTestOcr $ocr): void
{
    config()->set('rag-engine.ocr.vision', ['driver' => 'vision-test', 'model' => 'demo']);
    config()->set('rag-engine.defaults.ocr', 'vision');

    app(OcrManager::class)->extend('vision-test', function (array $config, string $name) use ($ocr): Ocr {
        expect($config['model'])->toBe('demo')->and($name)->toBe('vision');

        return $ocr;
    });

    app()->forgetInstance(ParserManager::class);
    app()->forgetInstance(EmbeddableFileResolver::class);
    app()->forgetInstance(IngestionPipeline::class);
}

it('does not parse images when OCR is the null engine (existing behaviour)', function () {
    $parsers = app(ParserManager::class);

    foreach (['image/png', 'image/jpeg', 'image/webp', 'image/tiff'] as $mime) {
        expect($parsers->supports($mime))->toBeFalse();
    }

    expect(fn () => $parsers->parse(pngBytes(), 'image/png'))->toThrow(ParsingException::class);
});

it('routes images to a custom OCR driver registered with extend()', function () {
    $ocr = new VisionTestOcr;
    useVisionOcr($ocr);

    $parsers = app(ParserManager::class);

    foreach (['image/png', 'image/jpeg', 'image/jpg', 'image/webp', 'image/tiff'] as $mime) {
        expect($parsers->supports($mime))->toBeTrue();
    }

    $doc = $parsers->parse(pngBytes(), 'IMAGE/PNG', ['filename' => 'scan.png']);

    expect($doc->text)->toBe('Invoice total 1200 EUR')
        ->and($doc->mimeType)->toBe('image/png')
        ->and($doc->metadata)->toMatchArray(['filename' => 'scan.png', 'ocr' => true, 'ocr_engine' => 'vision-test'])
        ->and($doc->sections[0]->type)->toBe('ocr')
        ->and($ocr->seen)->toBe(['image/png']);
});

it('indexes an uploaded image end-to-end through OCR', function () {
    useVisionOcr(new VisionTestOcr('Whiteboard: quarterly revenue target'));

    $path = sys_get_temp_dir().'/rag-ocr-'.bin2hex(random_bytes(4)).'.png';
    file_put_contents($path, pngBytes());

    try {
        $document = Rag::ingest(Rag::source()->file($path));
        expect($document->mime)->toBe('image/png');

        app(IngestionPipeline::class)->process($document);
    } finally {
        @unlink($path);
    }

    expect(Rag::search('quarterly revenue target')->first()?->content)->toContain('Whiteboard');
});

it('fails as unparsable when OCR returns no text', function () {
    useVisionOcr(new VisionTestOcr(''));

    expect(fn () => app(ParserManager::class)->parse(pngBytes(), 'image/png'))
        ->toThrow(ParsingException::class, 'OCR');
});

it('embeds an image file field of a model through OCR', function () {
    useVisionOcr(new VisionTestOcr('Signed receipt 42'));
    Storage::fake('uploads');
    Storage::disk('uploads')->put('receipts/r.jpg', pngBytes());

    $text = app(EmbeddableFileResolver::class)->resolve(['label' => 'Receipt', 'path' => 'receipts/r.jpg', 'disk' => 'uploads', 'mime' => null]);

    expect($text)->toBe('Signed receipt 42');
});

it('keeps rejecting image file fields when OCR is null', function () {
    config()->set('rag-engine.eloquent.on_unparsable_file', 'fail');
    app()->forgetInstance(EmbeddableFileResolver::class);
    Storage::fake('uploads');
    Storage::disk('uploads')->put('receipts/r.webp', pngBytes());

    expect(fn () => app(EmbeddableFileResolver::class)->resolve(['label' => 'R', 'path' => 'receipts/r.webp', 'disk' => 'uploads', 'mime' => null]))
        ->toThrow(UnsupportedFileException::class, 'image/webp');
});

it('ImageParser advertises its image types and ignores non-images', function () {
    $parser = new ImageParser(new VisionTestOcr);

    expect($parser->mimeTypes())->toContain('image/png', 'image/jpeg', 'image/webp', 'image/tiff')
        ->and($parser->supports('application/pdf'))->toBeFalse()
        ->and($parser->supports('image/svg+xml'))->toBeFalse();
});

it('ImageParser refuses to parse a type its OCR engine does not support', function () {
    (new ImageParser(new NullOcr))->parse(pngBytes(), 'image/png');
})->throws(ParsingException::class, 'No OCR engine');
