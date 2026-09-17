<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Pipeline;

use Sellinnate\RagEngine\Chunking\ChunkingService;
use Sellinnate\RagEngine\Events\DocumentChunked;
use Sellinnate\RagEngine\Events\IngestionFailed;
use Sellinnate\RagEngine\Indexing\Indexer;
use Sellinnate\RagEngine\Ingestion\Ingestor;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Parsing\ParserManager;
use Sellinnate\RagEngine\Preprocessing\PreprocessingPipeline;
use Throwable;

/**
 * Orchestrates the per-document ingestion pipeline (FR-OR-01/02): decrypt →
 * parse → preprocess (PII redaction) → chunk → embed → index, tracking state
 * transitions on the Document (pending → parsing → chunking → embedding →
 * indexed → failed).
 */
final class IngestionPipeline
{
    public const STATES = ['pending', 'parsing', 'chunking', 'embedding', 'indexed', 'failed'];

    public function __construct(
        private readonly Ingestor $ingestor,
        private readonly ParserManager $parsers,
        private readonly PreprocessingPipeline $preprocessing,
        private readonly ChunkingService $chunking,
        private readonly Indexer $indexer,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function process(Document $document, array $options = []): int
    {
        try {
            $this->transition($document, 'parsing');

            $content = $this->ingestor->content($document);
            $context = self::documentContext($document);
            $parsed = $this->parsers->parse($content, (string) ($document->mime ?? 'text/plain'), $context);

            // The document's declared title/filename describe the whole
            // document whatever its source (an Eloquent model is parsed as
            // plain text with no filename): make them available to the
            // contextual headers. A declared title beats one read from the file.
            $declared = [];
            if ($context['title'] !== null) {
                $declared['title'] = $context['title'];
            }
            if ($context['filename'] !== null && ! isset($parsed->metadata['filename'])) {
                $declared['filename'] = $context['filename'];
            }
            if ($declared !== []) {
                $parsed = $parsed->withMetadata($declared);
            }

            // Preprocess: clean + detect language + redact PII before indexing.
            $parsed = $this->preprocessing->process($parsed);

            $this->transition($document, 'chunking');
            if ($parsed->language !== null) {
                $document->forceFill(['language' => $parsed->language])->save();
            }

            $chunks = $this->chunking->chunk($parsed, $options);
            event(new DocumentChunked((string) $document->id, $document->tenant_id, count($chunks)));

            $this->transition($document, 'embedding');

            // Indexer sets status to 'indexed' and emits DocumentIndexed.
            return $this->indexer->index($document, $chunks, $options);
        } catch (Throwable $e) {
            $document->forceFill(['status' => 'failed'])->save();
            event(new IngestionFailed((string) $document->id, $document->tenant_id, $e->getMessage()));

            throw $e;
        }
    }

    /**
     * Document-level context handed to the parser: the declared `title` and
     * `filename` metadata (null when absent or blank).
     *
     * @return array{filename: ?string, title: ?string}
     */
    private static function documentContext(Document $document): array
    {
        $metadata = $document->metadata ?? [];
        $context = [];

        foreach (['filename', 'title'] as $key) {
            $value = $metadata[$key] ?? null;
            $context[$key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        return ['filename' => $context['filename'], 'title' => $context['title']];
    }

    private function transition(Document $document, string $state): void
    {
        $document->forceFill(['status' => $state])->save();
    }
}
