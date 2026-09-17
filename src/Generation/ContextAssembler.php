<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Generation;

use Sellinnate\RagEngine\Chunking\ContextualHeaderEnricher;
use Sellinnate\RagEngine\Contracts\Tokenizer;
use Sellinnate\RagEngine\Data\SearchHit;

/**
 * Assembles retrieved hits into a numbered, citation-ready context block
 * (FR-GE-01/03), respecting a token budget (FR-RR-04). Each passage is
 * preceded by its contextual header, e.g. `(Document: Quote > Section: Prices)`,
 * when the chunk has one.
 */
final class ContextAssembler
{
    public function __construct(private readonly Tokenizer $tokenizer) {}

    /**
     * @param  list<SearchHit>  $hits
     * @return array{context: string, citations: list<array{index: int, document_id: ?string, chunk_id: ?string}>}
     */
    public function assemble(array $hits, ?int $budgetTokens = null): array
    {
        $blocks = [];
        $citations = [];
        $spent = 0;
        $index = 0;

        foreach ($hits as $hit) {
            $index++;
            $text = $this->hitText($hit);
            $tokens = $this->tokenizer->count($text);

            if ($budgetTokens !== null && $blocks !== [] && $spent + $tokens > $budgetTokens) {
                break;
            }

            $blocks[] = "[{$index}] {$text}";
            $citations[] = [
                'index' => $index,
                'document_id' => $hit->documentId,
                'chunk_id' => $hit->chunkId,
            ];
            $spent += $tokens;
        }

        return ['context' => implode("\n\n", $blocks), 'citations' => $citations];
    }

    private function hitText(SearchHit $hit): string
    {
        $parent = $hit->metadata['parent_content'] ?? null;
        $text = is_string($parent) && $parent !== '' ? $parent : $hit->content;

        // Tell the model which document (and section) the passage comes from.
        $header = $hit->metadata[ContextualHeaderEnricher::METADATA_KEY] ?? null;

        return is_string($header) && $header !== '' ? "({$header})\n{$text}" : $text;
    }
}
