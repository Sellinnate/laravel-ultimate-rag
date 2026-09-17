<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Data\DocumentSection;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Parsing\ParserManager;
use Sellinnate\RagEngine\Parsing\PdfParser;
use Sellinnate\RagEngine\Tests\Support\PdfBuilder;

const QUOTE_FOOTER = "Sellinnate S.r.l. | Viale Belfiore 55, 50144 Firenze | P.IVA: 12345678901\t";

/**
 * A four-page quote whose pages all end with the company footer and a page
 * number, like the real "preventivo" that motivated the clean-up.
 */
function quotePdf(): string
{
    return PdfBuilder::make()
        ->page([
            'Preventivo — Gestionale per la distribuzione alimentare',
            'Destinatario',
            'Livio Cheese S.r.l.',
            'Contesto e obiettivo',
            'Livio Cheese seleziona e distribuisce specialità casearie a ristoranti,',
            'gastronomie e negozi specializzati.',
        ], QUOTE_FOOTER.'1')
        ->page([
            'Voci di costo — attivazione (una tantum)',
            'Analisi dei processi: mappatura del flusso ordine, merce, consegna,',
            '→ → →',
            'fattura e parametrizzazione dei ruoli',
            '€ 1.200,00',
            'Totale imponibile € 8.400,00',
        ], QUOTE_FOOTER.'2')
        ->page([
            'Piano di gestione annuale',
            'Il piano comprende hosting, backup e',
            'aggiornamenti della piattaforma.',
        ], QUOTE_FOOTER.'3')
        ->page([
            'Tempi e modalità di pagamento',
            'Bonifico bancario a 30 giorni data fattura.',
        ], QUOTE_FOOTER.'4')
        ->build();
}

it('keeps the repeated footer and stray symbols when clean-up is disabled (the original problem)', function () {
    $parser = new PdfParser(stripRepeatedLines: false, stripSymbolLines: false, joinWrappedLines: false);
    $text = $parser->parse(quotePdf(), 'application/pdf')->text;

    expect(substr_count($text, 'Viale Belfiore'))->toBe(4)
        ->and($text)->toContain('→ → →')
        ->and($text)->toContain("ristoranti,\ngastronomie");
});

it('removes a footer repeated on every page, whatever the page number', function () {
    $doc = (new PdfParser)->parse(quotePdf(), 'application/pdf');

    expect($doc->text)->not->toContain('Viale Belfiore')
        ->and($doc->text)->not->toContain('12345678901')
        ->and($doc->text)->toContain('Livio Cheese S.r.l.')
        ->and($doc->text)->toContain('Totale imponibile € 8.400,00')
        ->and($doc->metadata['page_count'])->toBe(4);

    foreach (array_filter($doc->sections, fn (DocumentSection $s) => $s->type === 'page') as $section) {
        expect($section->content)->not->toContain('Viale Belfiore')
            ->and($section->content)->not->toBe('');
    }
});

it('removes a repeated running header at the top of the pages', function () {
    $pdf = PdfBuilder::make()
        ->page(['Primo capitolo.', 'Testo del primo capitolo.'], header: 'Manuale operativo — Pagina 1 di 3')
        ->page(['Secondo capitolo.', 'Testo del secondo capitolo.'], header: 'Manuale operativo — Pagina 2 di 3')
        ->page(['Terzo capitolo.', 'Testo del terzo capitolo.'], header: 'Manuale operativo — Pagina 3 di 3')
        ->build();

    $text = (new PdfParser)->parse($pdf, 'application/pdf')->text;

    expect($text)->not->toContain('Manuale operativo')
        ->and($text)->toBe("Primo capitolo.\nTesto del primo capitolo.\n\nSecondo capitolo.\nTesto del secondo capitolo.\n\nTerzo capitolo.\nTesto del terzo capitolo.");
});

it('keeps lines that repeat on too few pages or away from the page edges', function () {
    $body = fn (string $n) => ['Intro '.$n.'.', 'Riga uno.', 'Riga due.', 'Nota ricorrente in mezzo.', 'Riga tre.', 'Riga quattro.', 'Fine '.$n.'.'];

    $pdf = PdfBuilder::make()
        ->page($body('A'), 'Solo sulla prima pagina')
        ->page($body('B'))
        ->page($body('C'))
        ->page($body('D'))
        ->build();

    $text = (new PdfParser)->parse($pdf, 'application/pdf')->text;

    // Mid-page repetition is content; a one-off footer is below the threshold.
    expect(substr_count($text, 'Nota ricorrente in mezzo.'))->toBe(4)
        ->and($text)->toContain('Solo sulla prima pagina')
        // "Riga uno." is repeated at the top edge of every page: stripped.
        ->and($text)->not->toContain('Riga uno.');
});

it('only strips repeated lines on documents with enough pages', function () {
    $pdf = PdfBuilder::make()
        ->page(['Pagina uno.'], 'Piè di pagina aziendale')
        ->page(['Pagina due.'], 'Piè di pagina aziendale')
        ->build();

    expect((new PdfParser)->parse($pdf, 'application/pdf')->text)->toBe("Pagina uno.\n\nPagina due.")
        ->and((new PdfParser(repeatedLineMinPages: 3))->parse($pdf, 'application/pdf')->text)
        ->toBe("Pagina uno.\nPiè di pagina aziendale\n\nPagina due.\nPiè di pagina aziendale");

    $single = PdfBuilder::make()->page(['Unica pagina.'], 'Piè di pagina aziendale')->build();
    expect((new PdfParser)->parse($single, 'application/pdf')->text)->toBe("Unica pagina.\nPiè di pagina aziendale");
});

it('honours the repeated-line threshold', function () {
    $pdf = PdfBuilder::make()
        ->page(['Uno.'], 'Bozza riservata')
        ->page(['Due.'], 'Bozza riservata')
        ->page(['Tre.'])
        ->page(['Quattro.'])
        ->build();

    // 2 of 4 pages: below the default 0.6, above 0.5.
    expect((new PdfParser)->parse($pdf, 'application/pdf')->text)->toContain('Bozza riservata')
        ->and((new PdfParser(repeatedLineThreshold: 0.5))->parse($pdf, 'application/pdf')->text)->not->toContain('Bozza riservata');
});

it('drops symbol-only lines but keeps symbols inside real text', function () {
    $pdf = PdfBuilder::make()->page([
        'Flusso: ordine → merce → consegna.',
        '• • •',
        '→ → →',
        '— — —',
        '* * *',
        '• Punto elenco con testo',
    ])->build();

    $text = (new PdfParser)->parse($pdf, 'application/pdf')->text;

    expect($text)->toBe("Flusso: ordine → merce → consegna.\n• Punto elenco con testo")
        ->and((new PdfParser(stripSymbolLines: false))->parse($pdf, 'application/pdf')->text)->toContain('• • •');
});

it('re-joins wrapped lines and keeps headings, rows and list items on their own lines', function () {
    $pdf = PdfBuilder::make()->page([
        'Cosa include',
        'Ordini clienti. Inserimento rapido anche da mobile, con',
        'ricerca per cliente e',
        '(filtri avanzati) sulla data.',
        'Il pagamento è suddiviso in:',
        '50% alla conferma',
        'Totale imponibile € 8.400,00',
        'piattaforma e-',
        'commerce inclusa.',
    ])->build();

    expect((new PdfParser)->parse($pdf, 'application/pdf')->text)->toBe(implode("\n", [
        'Cosa include',
        'Ordini clienti. Inserimento rapido anche da mobile, con ricerca per cliente e (filtri avanzati) sulla data.',
        'Il pagamento è suddiviso in:',
        '50% alla conferma',
        'Totale imponibile € 8.400,00 piattaforma e-commerce inclusa.',
    ]));

    expect((new PdfParser(joinWrappedLines: false))->parse($pdf, 'application/pdf')->text)
        ->toContain("mobile, con\nricerca per cliente e\n(filtri avanzati)");
});

it('joins a sentence that runs over a page break and separates other pages by a blank line', function () {
    $pdf = PdfBuilder::make()
        ->page(['Il servizio comprende hosting e'])
        ->page(['backup giornalieri.'])
        ->page(['Nuova sezione.'])
        ->page([])
        ->page(['Ultima pagina.'])
        ->build();

    expect((new PdfParser)->parse($pdf, 'application/pdf')->text)
        ->toBe("Il servizio comprende hosting e backup giornalieri.\n\nNuova sezione.\n\nUltima pagina.")
        ->and((new PdfParser(joinWrappedLines: false))->parse($pdf, 'application/pdf')->text)
        ->toBe("Il servizio comprende hosting e\n\nbackup giornalieri.\n\nNuova sezione.\n\nUltima pagina.");
});

it('reads the native PDF title', function () {
    $pdf = PdfBuilder::make()->title('Preventivo Livio Cheese')->page(['Testo.'])->build();

    expect((new PdfParser)->parse($pdf, 'application/pdf', ['filename' => 'q.pdf'])->metadata)
        ->toMatchArray(['title' => 'Preventivo Livio Cheese', 'filename' => 'q.pdf', 'page_count' => 1]);
});

it('fails closed on clean-up settings that could strip real content', function (array $arguments) {
    expect(fn () => new PdfParser(...$arguments))->toThrow(RagException::class);
})->with([
    'threshold 0' => [['repeatedLineThreshold' => 0.0]],
    'threshold above 1' => [['repeatedLineThreshold' => 1.5]],
    'no edge lines' => [['repeatedLineEdgeLines' => 0]],
]);

it('is configured from rag-engine.parsing.pdf', function () {
    config()->set('rag-engine.parsing.pdf.strip_repeated_lines', false);
    config()->set('rag-engine.parsing.pdf.strip_symbol_lines', false);
    config()->set('rag-engine.parsing.pdf.join_wrapped_lines', false);
    app()->forgetInstance(ParserManager::class);

    $text = app(ParserManager::class)->parse(quotePdf(), 'application/pdf')->text;

    expect(substr_count($text, 'Viale Belfiore'))->toBe(4)
        ->and($text)->toContain('→ → →');
});

it('fails closed on an invalid configured threshold', function () {
    config()->set('rag-engine.parsing.pdf.repeated_line_threshold', 2);
    app()->forgetInstance(ParserManager::class);

    expect(fn () => app(ParserManager::class))->toThrow(RagException::class);
});
