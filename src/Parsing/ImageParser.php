<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Parsing;

use Sellinnate\RagEngine\Contracts\Ocr;
use Sellinnate\RagEngine\Contracts\Parser;
use Sellinnate\RagEngine\Data\DocumentSection;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Exceptions\ParsingException;

/**
 * Image parser (FR-PA-02): turns a PNG / JPEG / WebP / TIFF upload into text by
 * routing it to the configured {@see Ocr} engine.
 *
 * It only claims an image type when the OCR engine supports it, so with the
 * default `null` engine images stay "unsupported" exactly as before (the
 * pipeline fails the document; an Eloquent file field follows
 * `eloquent.on_unparsable_file`). An image that yields no text is unparsable.
 */
final class ImageParser implements Parser
{
    private const MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/tiff'];

    /** Common aliases normalised to their canonical type. */
    private const ALIASES = ['image/jpg' => 'image/jpeg', 'image/pjpeg' => 'image/jpeg', 'image/tif' => 'image/tiff'];

    public function __construct(private readonly Ocr $ocr) {}

    public function supports(string $mimeType): bool
    {
        $mime = $this->normalize($mimeType);

        return in_array($mime, self::MIME_TYPES, true) && $this->ocr->supports($mime);
    }

    public function parse(string $contents, string $mimeType, array $context = []): ParsedDocument
    {
        $mime = $this->normalize($mimeType);

        if (! $this->supports($mime)) {
            throw new ParsingException("No OCR engine is configured for images of type [{$mime}].");
        }

        $text = trim($this->ocr->ocr($contents, $mime));

        if ($text === '') {
            throw new ParsingException("OCR extracted no text from the [{$mime}] image.");
        }

        return new ParsedDocument(
            text: $text,
            mimeType: $mime,
            sections: [new DocumentSection(type: 'ocr', content: $text)],
            metadata: array_filter([
                'filename' => $context['filename'] ?? null,
                'ocr' => true,
                'ocr_engine' => $this->ocr->name(),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    public function mimeTypes(): array
    {
        return self::MIME_TYPES;
    }

    private function normalize(string $mimeType): string
    {
        $mime = strtolower(trim(explode(';', $mimeType)[0]));

        return self::ALIASES[$mime] ?? $mime;
    }
}
