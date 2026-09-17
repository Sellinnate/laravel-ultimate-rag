<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Retrieval;

use Sellinnate\RagEngine\Chunking\ContextualHeaderEnricher;
use Sellinnate\RagEngine\Data\SearchHit;

/**
 * BM25 lexical scorer used for the keyword half of hybrid search (FR-RT-03).
 * Scores a candidate set against the query so RRF can fuse it with the vector
 * ranking — catching exact-term matches dense retrieval can miss.
 */
final class KeywordScorer
{
    public function __construct(
        private readonly float $k1 = 1.5,
        private readonly float $b = 0.75,
    ) {}

    /**
     * @param  list<SearchHit>  $candidates
     * @return list<SearchHit> Ranked best-first by BM25, with the BM25 score.
     */
    public function rank(string $query, array $candidates, int $topK): array
    {
        $queryTerms = $this->tokenize($query);

        if ($queryTerms === [] || $candidates === []) {
            return [];
        }

        $docTerms = [];
        $docLengths = [];
        $totalLength = 0;

        foreach ($candidates as $i => $hit) {
            $terms = $this->tokenize($this->scoredText($hit));
            $docTerms[$i] = array_count_values($terms);
            $docLengths[$i] = count($terms);
            $totalLength += count($terms);
        }

        $n = count($candidates);
        $avgLength = $totalLength / $n; // $n >= 1 (candidates is non-empty here)

        $df = [];
        foreach (array_unique($queryTerms) as $term) {
            $df[$term] = 0;
            foreach ($docTerms as $terms) {
                if (isset($terms[$term])) {
                    $df[$term]++;
                }
            }
        }

        $scored = [];
        foreach ($candidates as $i => $hit) {
            $score = 0.0;

            foreach (array_unique($queryTerms) as $term) {
                $tf = $docTerms[$i][$term] ?? 0;
                if ($tf === 0) {
                    continue;
                }

                $idf = log(1 + ($n - $df[$term] + 0.5) / ($df[$term] + 0.5));
                $denom = $tf + $this->k1 * (1 - $this->b + $this->b * ($avgLength > 0 ? $docLengths[$i] / $avgLength : 0));
                $score += $idf * ($tf * ($this->k1 + 1)) / ($denom ?: 1);
            }

            if ($score > 0.0) {
                $scored[] = $hit->withScore($score);
            }
        }

        usort($scored, static fn (SearchHit $a, SearchHit $b): int => $b->score <=> $a->score);

        return array_slice($scored, 0, max(0, $topK));
    }

    /**
     * The chunk text plus its contextual header (document title / section), so
     * a query naming the document also matches chunks that never repeat it.
     */
    private function scoredText(SearchHit $hit): string
    {
        $header = $hit->metadata[ContextualHeaderEnricher::METADATA_KEY] ?? null;

        return is_string($header) && $header !== '' ? $header."\n".$hit->content : $hit->content;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
