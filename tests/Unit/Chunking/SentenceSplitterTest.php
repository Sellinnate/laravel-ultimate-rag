<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Chunking\SentenceSplitter;

function sentencesOf(string $text, array $extra = []): array
{
    return array_column((new SentenceSplitter($extra))->split($text), 'text');
}

it('keeps a sentence whole across abbreviations, legal forms, numbers, dates and URLs', function (string $sentence) {
    expect(sentencesOf($sentence.' Seconda frase.'))->toBe([$sentence, 'Seconda frase.']);
})->with([
    'legal form S.r.l.' => 'Il contratto con Sellinnate S.r.l. Firenze è valido.',
    'legal form S.p.A.' => 'Fornitore: Acme S.p.A. Milano, sede legale.',
    'Italian title Dott.' => 'Il Dott. Rossi ha firmato.',
    'Italian title Sig.' => 'Gentile Sig. Bianchi, grazie.',
    'Italian title Dott.ssa' => 'La Dott.ssa Verdi conferma.',
    'Italian abbreviation ecc. + lowercase' => 'Ordini, fatture, ecc. e altro ancora.',
    'Italian abbreviation ecc. + uppercase' => 'Ordini, fatture, ecc. Tutto incluso.',
    'English e.g.' => 'Use a provider, e.g. Mistral, for EU data.',
    'English i.e.' => 'Pick one, i.e. The cheapest option.',
    'English Mr.' => 'Mr. Smith approved it.',
    'English Dr. and initials' => 'Dr. J. R. Tolkien wrote it.',
    'Euro amount with thousands' => 'Il totale è € 8.400,00 IVA esclusa.',
    'decimal number' => 'The ratio is 3.5 on average.',
    'dotted date' => 'Valido fino al 17.09.2026 compreso.',
    'URL' => 'See https://example.com/docs/v1.2/index.html for details.',
    'e-mail address' => 'Scrivere a info@selli.io per assistenza.',
    'ellipsis before lowercase' => 'Wait... what happened here?',
    'numbered article' => 'Vedi art. 5 del contratto.',
    'English No. + number' => 'Invoice No. 42 is overdue.',
    'P.IVA label' => 'Fornitore con P.IVA 12345678901 registrata.',
    'single initial' => 'N. preventivo PRV-2026 approvato.',
    'closing quote' => 'Disse "basta così." poi uscì.',
    'capitalised title On.' => 'Interviene On. Rossi in aula.',
    'capitalised title Ms.' => 'Ms. Smith approved it.',
    'attachment reference' => 'Vedi All. 2 del contratto.',
]);

it('ends a sentence at common words that are also abbreviations', function (string $first) {
    expect(sentencesOf($first.' Next one.'))->toBe([$first, 'Next one.']);
})->with([
    "That's all.",
    'Please go on.',
    'It took 5 ms.',
    'Charge your cell.',
]);

it('splits on real sentence ends', function () {
    expect(sentencesOf('Prima frase. Seconda frase! Terza domanda? Quarta… Quinta.'))
        ->toBe(['Prima frase.', 'Seconda frase!', 'Terza domanda?', 'Quarta…', 'Quinta.']);
});

it('ends a sentence after amounts, dates and closing quotes/brackets', function () {
    expect(sentencesOf('Il totale è € 8.400,00. Il saldo è dovuto.'))->toBe(['Il totale è € 8.400,00.', 'Il saldo è dovuto.'])
        ->and(sentencesOf('Scade il 17.09.2026. Poi si rinnova.'))->toBe(['Scade il 17.09.2026.', 'Poi si rinnova.'])
        ->and(sentencesOf('He said "stop." Then he left.'))->toBe(['He said "stop."', 'Then he left.'])
        ->and(sentencesOf('(Vedi sotto.) Il resto segue.'))->toBe(['(Vedi sotto.)', 'Il resto segue.']);
});

it('ends a sentence at a reference abbreviation that is not followed by a number', function () {
    expect(sentencesOf('The answer is no. We move on.'))->toBe(['The answer is no.', 'We move on.']);
});

it('treats blank lines as boundaries and single line breaks as spaces', function () {
    $text = "Cosa include\nOrdini clienti e fatture, con storico\ncompleto. Magazzino.\n\nTitolo senza punto\n\nAltro paragrafo";

    expect(sentencesOf($text))->toBe([
        "Cosa include\nOrdini clienti e fatture, con storico\ncompleto.",
        'Magazzino.',
        'Titolo senza punto',
        'Altro paragrafo',
    ]);
});

it('starts a new sentence at a bulleted or numbered line', function () {
    $text = "Il pagamento avviene in due tranche\n- 50% alla conferma\n- 50% al collaudo\n1. Primo punto\n2) Secondo punto";

    expect(sentencesOf($text))->toBe([
        'Il pagamento avviene in due tranche',
        '- 50% alla conferma',
        '- 50% al collaudo',
        '1. Primo punto',
        '2) Secondo punto',
    ]);
});

it('does not treat a mid-line number as a list marker', function () {
    expect(sentencesOf('The total is 1500. Next item.'))->toBe(['The total is 1500.', 'Next item.'])
        ->and(sentencesOf('Siamo al punto 3. Poi vediamo.'))->toBe(['Siamo al punto 3.', 'Poi vediamo.']);
});

it('reports character offsets that point at each sentence in multibyte text', function () {
    $text = "  Perché è così? Però sì.\n\nÈ l'ultima.";
    $sentences = (new SentenceSplitter)->split($text);

    expect($sentences)->toHaveCount(3);
    foreach ($sentences as $sentence) {
        expect(mb_substr($text, $sentence['offset'], mb_strlen($sentence['text'])))->toBe($sentence['text']);
    }
});

it('accepts extra abbreviations, with or without the dot', function () {
    $text = 'Vedi Rep. Contratto quadro. Fine.';

    expect(sentencesOf($text))->toBe(['Vedi Rep.', 'Contratto quadro.', 'Fine.'])
        ->and(sentencesOf($text, ['rep.']))->toBe(['Vedi Rep. Contratto quadro.', 'Fine.'])
        ->and(sentencesOf($text, ['REP', '  ']))->toBe(['Vedi Rep. Contratto quadro.', 'Fine.'])
        // A blank extra entry is ignored rather than matching a bare dot.
        ->and(sentencesOf('Fine . Poi', ['  ']))->toBe(['Fine .', 'Poi']);
});

it('returns nothing for blank text and a single span otherwise', function () {
    $splitter = new SentenceSplitter;

    expect($splitter->split(" \n\t "))->toBe([])
        ->and($splitter->spans('no terminator here'))->toBe([[0, 18]])
        ->and($splitter->spans('abc. Def.', 5))->toBe([[5, 9]]);
});

it('trims ranges and reports blank ones', function () {
    expect(SentenceSplitter::trimRange('  ab  ', 0, 6))->toBe([2, 4])
        ->and(SentenceSplitter::trimRange('    ', 0, 4))->toBeNull();
});

it('stays linear on hostile input', function (string $text) {
    $start = hrtime(true);
    $sentences = (new SentenceSplitter)->split($text.' Fine. Poi.');
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;

    expect($elapsedMs)->toBeLessThan(1000)
        ->and(array_slice(array_column($sentences, 'text'), -1))->toBe(['Poi.']);
})->with([
    'a huge token without spaces' => [str_repeat('a', 400_000).'.'],
    'a long run of dots without a space' => [str_repeat('.', 200_000).'x'],
    'many short dotted tokens' => [str_repeat('a.b.c.', 60_000)],
]);

it('treats a word longer than any abbreviation as an ordinary sentence end', function () {
    $long = str_repeat('è', 40); // 80 bytes, beyond the look-back window

    expect(sentencesOf("Parola {$long}. Seconda."))->toBe(["Parola {$long}.", 'Seconda.'])
        ->and(sentencesOf(str_repeat('x', 70).'. Seconda.'))->toBe([str_repeat('x', 70).'.', 'Seconda.']);
});

it('does not fail on invalid UTF-8', function () {
    $text = "Prima frase.\xC3 Seconda frase.\n\nTerza.";

    expect((new SentenceSplitter)->spans($text))->toBe([[0, strlen($text)]]);
});

it('counts offsets in UTF-8 whatever the internal encoding', function () {
    $previous = mb_internal_encoding();
    mb_internal_encoding('ISO-8859-1');

    try {
        $sentences = (new SentenceSplitter)->split('Perché sì. Così è.');
    } finally {
        mb_internal_encoding($previous);
    }

    expect(array_column($sentences, 'offset'))->toBe([0, 11]);
});
