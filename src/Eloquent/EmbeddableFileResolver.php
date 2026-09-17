<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Eloquent;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Sellinnate\RagEngine\Exceptions\ParsingException;
use Sellinnate\RagEngine\Exceptions\UnsupportedFileException;
use Sellinnate\RagEngine\Parsing\ParserManager;

/**
 * Turns an embeddable model's file field (a PDF/DOCX/… upload) into text
 * (FR-DX-05): reads the file (from a Laravel disk or a local path), parses it
 * with the registered parsers, and returns the extracted text to fold into the
 * model's embedding.
 *
 * Non-embeddable files — unsupported binaries (zip, executables, images when
 * no OCR engine is configured),
 * missing/unreadable files, empty files, or files over the size limit — are
 * handled by the `on_unparsable_file` policy: `skip` (log a warning and embed
 * the rest of the model) or `fail` (throw {@see UnsupportedFileException}).
 */
final class EmbeddableFileResolver
{
    /** Extension → MIME, so files resolve to the right parser deterministically. */
    private const EXTENSION_MIME = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
        'text' => 'text/plain',
        'md' => 'text/markdown',
        'markdown' => 'text/markdown',
        'html' => 'text/html',
        'htm' => 'text/html',
        'csv' => 'text/csv',
        'tsv' => 'text/csv',
        'json' => 'application/json',
        'xml' => 'application/xml',
        // Images are only embeddable when an OCR engine is configured.
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
    ];

    public function __construct(
        private readonly ParserManager $parsers,
        private readonly FilesystemFactory $files,
        private readonly string $onUnparsable = 'skip',
        private readonly int $maxBytes = 26_214_400,
    ) {}

    /**
     * @param  array{label: string, path: string, disk: string|null, mime: string|null}  $file
     * @return string|null The extracted text, or null when the file was skipped.
     */
    public function resolve(array $file): ?string
    {
        $path = $file['path'];
        $disk = $file['disk'];

        $bytes = $this->read($path, $disk);
        if ($bytes === null) {
            return null; // already rejected (skip mode) or threw (fail mode)
        }

        if ($bytes === '') {
            return $this->reject("file [{$path}] is empty");
        }

        $mime = $this->detectMime($file['mime'], $path, $bytes);

        if (! $this->parsers->supports($mime)) {
            return $this->reject("file [{$path}] is not embeddable: no parser for type [{$mime}] (binary/unsupported file?)");
        }

        try {
            $parsed = $this->parsers->parse($bytes, $mime, ['filename' => basename($path)]);
        } catch (ParsingException $e) {
            return $this->reject("file [{$path}] could not be parsed: {$e->getMessage()}");
        }

        return trim($parsed->text);
    }

    private function read(string $path, ?string $disk): ?string
    {
        if ($disk !== null) {
            $filesystem = $this->files->disk($disk);

            if (! $filesystem->exists($path)) {
                return $this->reject("file [{$path}] on disk [{$disk}] does not exist");
            }

            if ($filesystem->size($path) > $this->maxBytes) {
                return $this->reject("file [{$path}] exceeds the {$this->maxBytes}-byte limit");
            }

            return (string) $filesystem->get($path);
        }

        if (! is_file($path)) {
            return $this->reject("file [{$path}] does not exist");
        }

        $size = filesize($path);
        if ($size !== false && $size > $this->maxBytes) {
            return $this->reject("file [{$path}] exceeds the {$this->maxBytes}-byte limit");
        }

        return (string) file_get_contents($path);
    }

    private function detectMime(?string $explicit, string $path, string $bytes): string
    {
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (isset(self::EXTENSION_MIME[$ext])) {
            return self::EXTENSION_MIME[$ext];
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_buffer($finfo, $bytes);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }

        return 'application/octet-stream';
    }

    private function reject(string $message): null
    {
        if ($this->onUnparsable === 'fail') {
            throw new UnsupportedFileException("Cannot embed file — {$message}.");
        }

        logger()->warning("[rag-engine] skipped unembeddable file — {$message}.");

        return null;
    }
}
