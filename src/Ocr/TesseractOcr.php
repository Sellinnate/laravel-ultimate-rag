<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Ocr;

use Sellinnate\RagEngine\Contracts\Ocr;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * OCR via the Tesseract binary (FR-PA-02). Handles image files directly and
 * scanned PDFs by rasterising pages with `pdftoppm` (poppler) first. Requires
 * the `tesseract` (and, for PDFs, `pdftoppm`) binaries on the host.
 *
 * Best-effort: any failure (missing binary, unreadable page) yields an empty
 * string rather than breaking ingestion. Integration-tested only (needs the
 * binaries), so excluded from the line-coverage metric.
 *
 * @codeCoverageIgnore
 */
final class TesseractOcr implements Ocr
{
    private const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp', 'image/tiff', 'image/bmp', 'image/gif'];

    public function __construct(
        private readonly string $bin = 'tesseract',
        private readonly string $lang = 'eng',
        private readonly string $pdftoppm = 'pdftoppm',
        private readonly int $timeout = 120,
    ) {}

    public function ocr(string $contents, string $mimeType): string
    {
        $mime = strtolower(trim($mimeType));

        if (! $this->supports($mime)) {
            return '';
        }

        try {
            return str_contains($mime, 'pdf')
                ? $this->ocrPdf($contents)
                : $this->ocrImage($contents);
        } catch (Throwable) {
            return '';
        }
    }

    public function supports(string $mimeType): bool
    {
        $mime = strtolower(trim($mimeType));

        return $mime === 'application/pdf' || in_array($mime, self::IMAGE_TYPES, true);
    }

    public function name(): string
    {
        return 'tesseract';
    }

    private function ocrImage(string $bytes): string
    {
        $tmp = $this->tempFile($bytes, 'rag_ocr_img_');

        try {
            $process = new Process([$this->bin, $tmp, 'stdout', '-l', $this->lang]);
            $process->setTimeout($this->timeout);
            $process->run();

            return trim($process->getOutput());
        } finally {
            @unlink($tmp);
        }
    }

    private function ocrPdf(string $bytes): string
    {
        $pdf = $this->tempFile($bytes, 'rag_ocr_pdf_');
        $prefix = $pdf.'_page';

        try {
            $raster = new Process([$this->pdftoppm, '-png', '-r', '200', $pdf, $prefix]);
            $raster->setTimeout($this->timeout);
            $raster->run();

            $pages = glob($prefix.'*.png') ?: [];
            sort($pages);

            $texts = [];
            foreach ($pages as $page) {
                $process = new Process([$this->bin, $page, 'stdout', '-l', $this->lang]);
                $process->setTimeout($this->timeout);
                $process->run();
                $texts[] = trim($process->getOutput());
                @unlink($page);
            }

            return trim(implode("\n\n", array_filter($texts)));
        } finally {
            @unlink($pdf);
        }
    }

    private function tempFile(string $bytes, string $prefix): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), $prefix);
        file_put_contents($tmp, $bytes);

        return $tmp;
    }
}
