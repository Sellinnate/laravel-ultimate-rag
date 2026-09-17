<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Generation\ContextAssembler;
use Sellinnate\RagEngine\Tokenization\ApproximateTokenizer;

it('tells the model which document each passage comes from', function () {
    $hits = [
        new SearchHit('a', 0.9, 'Totale imponibile € 8.400,00.', ['context_header' => 'Document: Preventivo Livio Cheese'], 'd1', 'c1'),
        new SearchHit('b', 0.8, 'Testo senza intestazione.', [], 'd2', 'c2'),
        new SearchHit('c', 0.7, 'figlio', ['context_header' => 'Document: Manuale', 'parent_content' => 'Genitore completo.'], 'd3', 'c3'),
    ];

    $assembled = (new ContextAssembler(new ApproximateTokenizer))->assemble($hits);

    expect($assembled['context'])->toBe(implode("\n\n", [
        "[1] (Document: Preventivo Livio Cheese)\nTotale imponibile € 8.400,00.",
        '[2] Testo senza intestazione.',
        "[3] (Document: Manuale)\nGenitore completo.",
    ]))
        ->and(array_column($assembled['citations'], 'chunk_id'))->toBe(['c1', 'c2', 'c3']);
});
