<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Preprocessing\TextCleaner;

beforeEach(fn () => $this->cleaner = new TextCleaner);

it('normalizes line endings and collapses blank lines', function () {
    expect($this->cleaner->clean("a\r\n\r\n\r\n\r\nb"))->toBe("a\n\nb");
});

it('collapses runs of spaces and trims trailing line spaces', function () {
    expect($this->cleaner->clean("a    b   \nc"))->toBe("a b\nc");
});

it('strips control and zero-width characters', function () {
    expect($this->cleaner->clean("a\x00b\u{200B}c"))->toBe('abc');
});

it('trims surrounding whitespace', function () {
    expect($this->cleaner->clean('   hello   '))->toBe('hello');
});

it('runs as a preprocessing stage on a parsed document', function () {
    $doc = new ParsedDocument("messy    text\r\n\r\n\r\nhere", 'text/plain');

    expect($this->cleaner->process($doc)->text)->toBe("messy text\n\nhere")
        ->and($this->cleaner->name())->toBe('text-cleaner');
});

it('keeps line breaks and turns whitespace-only blank lines into one paragraph break', function () {
    expect($this->cleaner->clean("Titolo  \nRiga uno\n   \n \t \n\nParagrafo"))->toBe("Titolo\nRiga uno\n\nParagrafo");
});
