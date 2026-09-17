<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Ingestion;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Storage;
use Sellinnate\RagEngine\Exceptions\RagException;

/**
 * Builds {@see IngestionSource}s from the supported origins (FR-IN-01..05).
 */
final class SourceFactory
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly SsrfGuard $ssrf = new SsrfGuard,
    ) {}

    /**
     * Raw text (FR-IN-02).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function text(string $text, array $metadata = []): IngestionSource
    {
        return new IngestionSource($text, 'text/plain', IngestionSource::TYPE_TEXT, $metadata);
    }

    /**
     * A local file path or uploaded file (FR-IN-01).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function file(string $path, array $metadata = []): IngestionSource
    {
        if (! is_file($path)) {
            throw new RagException("File not found: {$path}.");
        }

        $contents = (string) file_get_contents($path);
        $filename = basename($path);

        return new IngestionSource(
            $contents,
            $this->guessMime($filename, $contents),
            IngestionSource::TYPE_UPLOAD,
            [...$metadata, 'filename' => $filename],
        );
    }

    /**
     * An object on a configured storage disk: S3, R2, local (FR-IN-05).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function storage(string $disk, string $path, array $metadata = []): IngestionSource
    {
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            throw new RagException("Storage object not found: [{$disk}] {$path}.");
        }

        $contents = (string) $storage->get($path);

        return new IngestionSource(
            $contents,
            $this->guessMime($path, $contents),
            IngestionSource::TYPE_STORAGE,
            [...$metadata, 'disk' => $disk, 'key' => $path],
        );
    }

    /**
     * Fetch and ingest a URL (FR-IN-03).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function url(string $url, array $metadata = []): IngestionSource
    {
        $ips = $this->ssrf->assertSafe($url);

        // Pin the connection to a validated IP so DNS-rebinding (a second
        // resolution returning an internal address) cannot bypass the SSRF check.
        // NOTE: pinning relies on the curl transport (the default when ext-curl is
        // present); on a pure-stream handler the pin is ignored and rebinding
        // protection degrades to the pre-fetch check only.
        $parts = (array) parse_url($url);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80));

        // Redirects are not followed: a permitted host could 302 into the
        // internal network, defeating the SSRF check (FR-IN-03, NFR-SE).
        $response = $this->http
            ->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ips[0]}"]],
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new RagException("Failed to fetch URL [{$url}]: HTTP {$response->status()}.");
        }

        $mime = $response->header('Content-Type') ?: 'text/html';
        $mime = trim(explode(';', $mime)[0]);

        return new IngestionSource(
            $response->body(),
            $mime === '' ? 'text/html' : $mime,
            IngestionSource::TYPE_URL,
            [...$metadata, 'url' => $url],
        );
    }

    /**
     * An Eloquent record (FR-IN-04). Reads the given fields (or a
     * `toRagContent()` method) to build the text.
     *
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $metadata
     */
    public function eloquent(Model $model, array $fields = [], array $metadata = []): IngestionSource
    {
        if (method_exists($model, 'toRagContent')) {
            $content = (string) $model->toRagContent();
        } else {
            $parts = [];
            foreach ($fields as $field) {
                $value = $model->getAttribute($field);
                if ($value !== null) {
                    $parts[] = is_scalar($value) ? (string) $value : json_encode($value);
                }
            }
            $content = implode("\n\n", $parts);
        }

        return new IngestionSource(
            $content,
            'text/plain',
            IngestionSource::TYPE_ELOQUENT,
            [
                ...$metadata,
                'model' => $model::class,
                'model_id' => $model->getKey(),
                'document_key' => $model::class.':'.$model->getKey(),
            ],
        );
    }

    private function guessMime(string $filename, string $contents): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'txt' => 'text/plain',
            'md', 'markdown' => 'text/markdown',
            'html', 'htm' => 'text/html',
            'xml' => 'application/xml',
            'csv' => 'text/csv',
            'tsv' => 'text/tab-separated-values',
            'json' => 'application/json',
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'tif', 'tiff' => 'image/tiff',
            default => $this->sniffMime($contents),
        };
    }

    private function sniffMime(string $contents): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($contents);

        return $detected === false ? 'application/octet-stream' : $detected;
    }
}
