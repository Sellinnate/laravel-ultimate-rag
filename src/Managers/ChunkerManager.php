<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Managers;

use Sellinnate\RagEngine\Chunking\FixedSizeChunker;
use Sellinnate\RagEngine\Chunking\MarkdownChunker;
use Sellinnate\RagEngine\Chunking\RecursiveCharacterChunker;
use Sellinnate\RagEngine\Chunking\SentenceChunker;
use Sellinnate\RagEngine\Chunking\SentenceSplitter;
use Sellinnate\RagEngine\Contracts\Chunker;
use Sellinnate\RagEngine\Contracts\Tokenizer;

/**
 * Resolves chunking strategies (FR-CH-10, pluggable driver).
 *
 * @extends DriverManager<Chunker>
 */
final class ChunkerManager extends DriverManager
{
    protected function configSection(): string
    {
        return 'chunkers';
    }

    public function getDefaultDriver(): string
    {
        return (string) $this->app->make('config')->get('rag-engine.defaults.chunker', 'recursive');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createFixedDriver(array $config): Chunker
    {
        return new FixedSizeChunker($this->app->make(Tokenizer::class));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createRecursiveDriver(array $config): Chunker
    {
        return new RecursiveCharacterChunker($this->app->make(Tokenizer::class), $this->sentenceSplitter($config));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createSentenceDriver(array $config): Chunker
    {
        return new SentenceChunker($this->app->make(Tokenizer::class), $this->sentenceSplitter($config));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createMarkdownDriver(array $config): Chunker
    {
        return new MarkdownChunker($this->app->make(Tokenizer::class), $this->sentenceSplitter($config));
    }

    /**
     * Sentence boundaries honour the extra abbreviations configured globally
     * (`chunking.abbreviations`) and per chunker connection (`abbreviations`).
     *
     * @param  array<string, mixed>  $config
     */
    private function sentenceSplitter(array $config): SentenceSplitter
    {
        $abbreviations = [];

        foreach ([$this->app->make('config')->get('rag-engine.chunking.abbreviations', []), $config['abbreviations'] ?? []] as $list) {
            foreach (is_array($list) ? $list : [] as $abbreviation) {
                if (is_string($abbreviation) && trim($abbreviation) !== '') {
                    $abbreviations[] = $abbreviation;
                }
            }
        }

        return new SentenceSplitter($abbreviations);
    }
}
