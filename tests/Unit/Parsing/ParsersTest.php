<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Exceptions\ParsingException;
use Sellinnate\RagEngine\Parsing\CsvParser;
use Sellinnate\RagEngine\Parsing\HtmlParser;
use Sellinnate\RagEngine\Parsing\JsonParser;
use Sellinnate\RagEngine\Parsing\MarkdownParser;
use Sellinnate\RagEngine\Parsing\PlainTextParser;
use Sellinnate\RagEngine\Parsing\XmlParser;

it('PlainTextParser passes text through and advertises its types', function () {
    $parser = new PlainTextParser;

    expect($parser->supports('text/plain'))->toBeTrue()
        ->and($parser->supports('application/pdf'))->toBeFalse()
        ->and($parser->parse('hello', 'text/plain', ['filename' => 'a.txt'])->text)->toBe('hello')
        ->and($parser->parse('hello', 'text/plain', ['filename' => 'a.txt'])->metadata['filename'])->toBe('a.txt');
});

it('MarkdownParser extracts heading sections with levels (FR-PA-10)', function () {
    $md = "# Title\n\nIntro text\n\n## Section A\n\nBody";
    $doc = (new MarkdownParser)->parse($md, 'text/markdown');

    expect($doc->sections)->toHaveCount(2)
        ->and($doc->sections[0]->content)->toBe('Title')
        ->and($doc->sections[0]->level)->toBe(1)
        ->and($doc->sections[1]->content)->toBe('Section A')
        ->and($doc->sections[1]->level)->toBe(2)
        ->and($doc->text)->toContain('Intro text');
});

it('HtmlParser strips scripts and extracts headings + title (FR-PA-06)', function () {
    $html = '<html><head><title>Doc</title></head><body><h1>Heading</h1><p>Para</p><script>alert(1)</script></body></html>';
    $doc = (new HtmlParser)->parse($html, 'text/html');

    expect($doc->metadata['title'])->toBe('Doc')
        ->and($doc->text)->toContain('Heading')
        ->and($doc->text)->toContain('Para')
        ->and($doc->text)->not->toContain('alert')
        ->and($doc->sections[0]->content)->toBe('Heading');
});

it('XmlParser extracts text content', function () {
    $xml = '<root><item>One</item><item>Two</item></root>';
    $doc = (new XmlParser)->parse($xml, 'application/xml');

    expect($doc->text)->toContain('One')->toContain('Two')
        ->and($doc->metadata['root'])->toBe('root');
});

it('XmlParser rejects DOCTYPE/ENTITY documents (XXE defence, FR-SEC-08)', function () {
    $xxe = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><root>&xxe;</root>';

    (new XmlParser)->parse($xxe, 'application/xml');
})->throws(ParsingException::class, 'XXE');

it('XmlParser does not expand entities and rejects malformed xml', function () {
    expect(fn () => (new XmlParser)->parse('<root><unclosed></root>', 'application/xml'))
        ->toThrow(ParsingException::class);
});

it('CsvParser preserves tabular structure with header-paired cells (FR-PA-07/09)', function () {
    $csv = "name,age\nAlice,30\nBob,25";
    $doc = (new CsvParser)->parse($csv, 'text/csv');

    expect($doc->metadata['columns'])->toBe(['name', 'age'])
        ->and($doc->metadata['row_count'])->toBe(2)
        ->and($doc->text)->toContain('name: Alice')->toContain('age: 30')
        ->and($doc->sections[0]->type)->toBe('table')
        ->and($doc->sections[0]->metadata['rows'][0])->toBe(['name' => 'Alice', 'age' => '30']);
});

it('CsvParser handles TSV via mime', function () {
    $tsv = "a\tb\n1\t2";
    $doc = (new CsvParser)->parse($tsv, 'text/tab-separated-values');

    expect($doc->metadata['columns'])->toBe(['a', 'b'])
        ->and($doc->text)->toContain('a: 1');
});

it('JsonParser flattens nested structure to path:value lines', function () {
    $json = '{"user":{"name":"Ada","roles":["admin","dev"]},"active":true}';
    $doc = (new JsonParser)->parse($json, 'application/json');

    expect($doc->text)->toContain('user.name: Ada')
        ->toContain('user.roles.0: admin')
        ->toContain('active: true')
        ->and($doc->metadata['json']['user']['name'])->toBe('Ada');
});

it('JsonParser rejects invalid json', function () {
    (new JsonParser)->parse('{not valid', 'application/json');
})->throws(ParsingException::class, 'Invalid JSON');

it('HtmlParser keeps block structure as paragraphs and lines', function () {
    $html = <<<'HTML'
<html><body>
  <!-- commento --><h1>Preventivo</h1><p>Il cliente è
     Livio Cheese.</p><p>Secondo<br>paragrafo</p>
  <ul><li>Ordini</li><li>Fatture</li></ul>
  <table><tr><th>Voce</th><th>Importo</th></tr><tr><td>Licenza</td><td>€ 3.900,00</td></tr></table>
  <pre>riga 1
riga 2</pre>
  <div><span>Inline</span> <b>testo</b></div>
</body></html>
HTML;

    expect((new HtmlParser)->parse($html, 'text/html')->text)->toBe(implode("\n", [
        'Preventivo',
        '',
        'Il cliente è Livio Cheese.',
        '',
        'Secondo',
        'paragrafo',
        '',
        'Ordini',
        'Fatture',
        '',
        "Voce\tImporto",
        "Licenza\t€ 3.900,00",
        '',
        'riga 1',
        'riga 2',
        '',
        'Inline testo',
    ]));
});
