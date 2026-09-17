<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Chunking\OffsetMap;
use Sellinnate\RagEngine\Chunking\RecursiveCharacterChunker;
use Sellinnate\RagEngine\Chunking\SentenceChunker;
use Sellinnate\RagEngine\Chunking\SentenceSplitter;
use Sellinnate\RagEngine\Contracts\Chunker;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Data\TextChunk;
use Sellinnate\RagEngine\Tokenization\ApproximateTokenizer;

/**
 * An Italian quote-like text: abbreviations, legal forms, amounts and dates in
 * every sentence, paragraphs and a heading.
 */
function quoteText(): string
{
    $sentences = [
        'Il presente preventivo è emesso da Sellinnate S.r.l. per Livio Cheese S.r.l. con sede a Milano.',
        "Il referente è il Dott. Rossi, affiancato dalla Dott.ssa Verdi e dal Sig. Bianchi per l'assistenza.",
        'La licenza costa € 3.900,00 e la configurazione € 1.200,00, per un totale di € 8.400,00 IVA esclusa.',
        "L'offerta è valida fino al 17.09.2026 e i dettagli sono su https://selli.io/offerte/v1.2 o via info@selli.io.",
        'Sono inclusi ordini, fatture, DDT, ecc. e la reportistica, e.g. le dashboard KPI, i.e. tutto il necessario.',
        'Mr. Smith, del fornitore Acme S.p.A., ha confermato i tempi... non ci sono ritardi previsti.',
    ];

    $paragraph = implode(' ', $sentences);

    return "Tempi e modalità di pagamento\n".$paragraph."\n\n".$paragraph."\n\n".$paragraph;
}

/**
 * Every chunk must end exactly where one of the text's sentences ends.
 *
 * @param  list<TextChunk>  $chunks
 */
function assertChunksEndOnSentences(string $text, array $chunks): void
{
    $ends = [];
    foreach ((new SentenceSplitter)->split($text) as $sentence) {
        $ends[$sentence['offset'] + mb_strlen($sentence['text'])] = true;
    }

    foreach ($chunks as $chunk) {
        expect(isset($ends[$chunk->offset + mb_strlen($chunk->content)]))
            ->toBeTrue("Chunk {$chunk->index} ends mid-sentence: …".mb_substr($chunk->content, -40));
    }
}

dataset('sentence-aware chunkers', [
    'recursive' => fn (): Chunker => new RecursiveCharacterChunker(new ApproximateTokenizer),
    'sentence' => fn (): Chunker => new SentenceChunker(new ApproximateTokenizer),
]);

it('ends every chunk on a sentence boundary and never exceeds the size', function (Chunker $chunker) {
    $text = quoteText();
    $chunks = $chunker->chunk(new ParsedDocument($text, 'text/plain'), ['size' => 300, 'overlap' => 120]);

    expect(count($chunks))->toBeGreaterThan(3);
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk->content))->toBeLessThanOrEqual(300);
    }
    assertChunksEndOnSentences($text, $chunks);
})->with('sentence-aware chunkers');

it('returns exact source slices with character offsets', function (Chunker $chunker) {
    $text = quoteText();
    $chunks = $chunker->chunk(new ParsedDocument($text, 'text/plain'), ['size' => 250, 'overlap' => 100]);

    $previous = -1;
    foreach ($chunks as $chunk) {
        expect(mb_substr($text, $chunk->offset, mb_strlen($chunk->content)))->toBe($chunk->content)
            ->and($chunk->offset)->toBeGreaterThan($previous)
            ->and($chunk->metadata['offset'])->toBe($chunk->offset);
        $previous = $chunk->offset;
    }
})->with('sentence-aware chunkers');

it('overlaps in whole sentences within the overlap budget', function (Chunker $chunker) {
    $text = quoteText();
    $chunks = $chunker->chunk(new ParsedDocument($text, 'text/plain'), ['size' => 300, 'overlap' => 150]);
    $starts = array_column((new SentenceSplitter)->split($text), 'offset');

    $overlapping = 0;
    for ($i = 1; $i < count($chunks); $i++) {
        $previousEnd = $chunks[$i - 1]->offset + mb_strlen($chunks[$i - 1]->content);

        // Each chunk starts at the start of a sentence…
        expect($starts)->toContain($chunks[$i]->offset);

        // …and repeats at most `overlap` characters of the previous chunk.
        if ($chunks[$i]->offset < $previousEnd) {
            $overlapping++;
            expect($previousEnd - $chunks[$i]->offset)->toBeLessThanOrEqual(150);
        }
    }

    expect($overlapping)->toBeGreaterThan(0);
})->with('sentence-aware chunkers');

it('does not overlap when the overlap is zero', function (Chunker $chunker) {
    $text = quoteText();
    $chunks = $chunker->chunk(new ParsedDocument($text, 'text/plain'), ['size' => 300, 'overlap' => 0]);

    for ($i = 1; $i < count($chunks); $i++) {
        expect($chunks[$i]->offset)->toBeGreaterThanOrEqual($chunks[$i - 1]->offset + mb_strlen($chunks[$i - 1]->content));
    }
})->with('sentence-aware chunkers');

it('splits a single over-long sentence between words, then characters', function (Chunker $chunker) {
    $long = str_repeat('parola ', 40).str_repeat('x', 70).' fine.';
    $chunks = $chunker->chunk(new ParsedDocument($long, 'text/plain'), ['size' => 50, 'overlap' => 0]);

    expect(count($chunks))->toBeGreaterThan(5);
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk->content))->toBeLessThanOrEqual(50)
            ->and($chunk->content)->not->toStartWith(' ')
            ->and($chunk->content)->not->toEndWith(' ');
        // Words are only cut when a single word is longer than the size.
        if (! str_contains($chunk->content, 'x')) {
            expect(preg_match('/^(parola ?)+$/', $chunk->content))->toBe(1);
        }
    }
    expect(implode('', array_map(fn ($c) => str_replace(' ', '', $c->content), $chunks)))
        ->toBe(str_replace(' ', '', $long));
})->with('sentence-aware chunkers');

it('prefers line breaks to words inside an over-long sentence (tables)', function (Chunker $chunker) {
    $rows = [];
    for ($i = 1; $i <= 12; $i++) {
        $rows[] = "Voce di costo numero {$i}\t€ {$i}.000,00";
    }
    $text = implode("\n", $rows);
    $chunks = $chunker->chunk(new ParsedDocument($text, 'text/plain'), ['size' => 100, 'overlap' => 0]);

    expect(count($chunks))->toBeGreaterThan(1);
    foreach ($chunks as $chunk) {
        foreach (explode("\n", $chunk->content) as $line) {
            expect($rows)->toContain($line);
        }
    }
})->with('sentence-aware chunkers');

it('preserves line and paragraph breaks inside chunks', function (Chunker $chunker) {
    $text = "[Document]\nQuote Livio Cheese\n\n[File]\nPreventivo.\nCosa include\nOrdini clienti.";
    $chunks = $chunker->chunk(new ParsedDocument($text, 'text/plain'), ['size' => 1000, 'overlap' => 0]);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->content)->toBe($text)
        ->and($chunks[0]->content)->not->toContain('Cheese [File]');
})->with('sentence-aware chunkers');

it('returns nothing for blank text', function (Chunker $chunker) {
    expect($chunker->chunk(new ParsedDocument(" \n\n ", 'text/plain')))->toBe([]);
})->with('sentence-aware chunkers');

it('keeps a paragraph that fits whole with the recursive strategy', function () {
    $para = 'Prima frase del paragrafo. Seconda frase del paragrafo.';
    $text = "Intro breve.\n\n".$para;
    $chunks = (new RecursiveCharacterChunker(new ApproximateTokenizer))
        ->chunk(new ParsedDocument($text, 'text/plain'), ['size' => mb_strlen($para) + 2, 'overlap' => 0]);

    expect(array_column(array_map(fn ($c) => $c->toArray(), $chunks), 'content'))->toBe(['Intro breve.', $para]);
});

it('packs sentences densely across paragraphs with the sentence strategy', function () {
    $text = "Uno. Due.\n\nTre. Quattro.";
    $chunks = (new SentenceChunker(new ApproximateTokenizer))
        ->chunk(new ParsedDocument($text, 'text/plain'), ['size' => 16, 'overlap' => 0]);

    expect(array_map(fn ($c) => $c->content, $chunks))->toBe(["Uno. Due.\n\nTre.", 'Quattro.'])
        ->and($chunks[0]->metadata['offset_unit'])->toBe('char');
});

it('treats the sentence overlap as characters, not as a sentence count', function () {
    // Before v1.4 an overlap of 200 meant "200 sentences", so each chunk only
    // advanced by a single sentence.
    $text = implode(' ', array_map(fn (int $i): string => "Frase numero {$i} del documento.", range(1, 60)));
    $chunks = (new SentenceChunker(new ApproximateTokenizer))
        ->chunk(new ParsedDocument($text, 'text/plain'), ['size' => 1000, 'overlap' => 200]);

    expect(count($chunks))->toBeLessThan(5);
});

it('uses the configured extra abbreviations', function () {
    $splitter = new SentenceSplitter(['Rep']);
    $text = 'Vedi Rep. Contratto quadro firmato. Seconda frase qui.';

    $plain = (new SentenceChunker(new ApproximateTokenizer))->chunk(new ParsedDocument($text, 'text/plain'), ['size' => 30, 'overlap' => 0]);
    $custom = (new SentenceChunker(new ApproximateTokenizer, $splitter))->chunk(new ParsedDocument($text, 'text/plain'), ['size' => 40, 'overlap' => 0]);

    expect($plain[0]->content)->toBe('Vedi Rep.')
        ->and($custom[0]->content)->toBe('Vedi Rep. Contratto quadro firmato.');
});

it('repeats the trailing sentences of a paragraph that is too long to overlap whole', function () {
    $paragraph = fn (string $n): string => "Prima frase del paragrafo {$n} con qualche parola. "
        ."Seconda frase del paragrafo {$n} anche lei. Ultima frase {$n}.";
    $text = $paragraph('A')."\n\n".$paragraph('B')."\n\n".$paragraph('C');

    $chunks = (new RecursiveCharacterChunker(new ApproximateTokenizer))
        ->chunk(new ParsedDocument($text, 'text/plain'), ['size' => 140, 'overlap' => 40]);

    expect(array_map(fn ($c) => $c->content, $chunks))->toBe([
        $paragraph('A'),
        "Ultima frase A.\n\n".$paragraph('B'),
        "Ultima frase B.\n\n".$paragraph('C'),
    ]);
});

it('drops an overlap tail that would push the next chunk past the size', function () {
    $short = 'Frase breve di quaranta caratteri circa.';
    $long = 'Questa seconda frase invece è molto più lunga e occupa quasi tutto lo spazio.';

    $chunks = (new SentenceChunker(new ApproximateTokenizer))
        ->chunk(new ParsedDocument($short.' '.$long, 'text/plain'), ['size' => 100, 'overlap' => 50]);

    expect(array_map(fn ($c) => $c->content, $chunks))->toBe([$short, $long]);
});

it('converts byte offsets to character offsets in any order', function () {
    $map = new OffsetMap('àèìòù abc');

    expect($map->charOffset(10))->toBe(5)
        ->and($map->charOffset(4))->toBe(2)
        ->and($map->charOffset(12))->toBe(7);
});
