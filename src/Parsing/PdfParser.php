<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Parsing;

use Sellinnate\RagEngine\Contracts\Ocr;
use Sellinnate\RagEngine\Contracts\Parser;
use Sellinnate\RagEngine\Data\DocumentSection;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Exceptions\ParsingException;
use Sellinnate\RagEngine\Exceptions\RagException;
use Smalot\PdfParser\Parser as SmalotParser;
use Throwable;

/**
 * Text-PDF parser (FR-PA-01) backed by the pure-PHP smalot/pdfparser.
 *
 * The dependency is optional (suggested, not required) so search-only consumers
 * stay lean; {@see isAvailable()} reports whether it can be used. Each PDF page
 * is preserved as a section to keep page structure (FR-PA-10).
 *
 * Extraction clean-up (each step can be switched off, see `parsing.pdf`):
 *
 * - **Repeated lines** — running headers/footers (company address, "Page 2
 *   of 4") are removed: a line near the top or bottom of a page (within
 *   `repeatedLineEdgeLines` lines) that, once digits and whitespace are
 *   normalised, appears on at least `repeatedLineThreshold` of the pages (and
 *   on 2 pages or more) is dropped. Only for PDFs with at least
 *   `repeatedLineMinPages` pages (never fewer than 2).
 * - **Symbol-only lines** — lines made only of arrows, bullets, box-drawing
 *   or geometric symbols (stray `→ → →` from diagrams) are dropped.
 * - **Wrapped lines** — a PDF breaks every visual line. A line that does not
 *   end a sentence (no `.`, `!`, `?`, `:` or `;`) and is followed by a line
 *   starting in lowercase is joined to it with a space, also across pages.
 *   Other line breaks (headings, table rows, list items) are kept, and pages
 *   are separated by a blank line (a paragraph break).
 *
 * Scanned (image-only) PDFs have no text layer; when an {@see Ocr} engine is
 * configured and the extracted text is below `ocrMinChars`, the parser falls
 * back to OCR (FR-PA-02).
 */
final class PdfParser implements Parser
{
    /** Arrows, bullets, box drawing, blocks, geometric shapes, dingbats and ASCII rule characters. */
    private const SYMBOL_LINE = '/^[\s\x{2190}-\x{21FF}\x{27F0}-\x{27FF}\x{2900}-\x{297F}\x{2B00}-\x{2BFF}\x{2500}-\x{25FF}\x{2022}\x{2023}\x{2043}\x{2219}\x{00B7}\x{2013}\x{2014}\x{2700}-\x{27BF}\-\*_=|~>]+$/u';

    public function __construct(
        private readonly ?Ocr $ocr = null,
        private readonly int $ocrMinChars = 16,
        private readonly bool $stripRepeatedLines = true,
        private readonly float $repeatedLineThreshold = 0.6,
        private readonly int $repeatedLineMinPages = 2,
        private readonly int $repeatedLineEdgeLines = 3,
        private readonly bool $stripSymbolLines = true,
        private readonly bool $joinWrappedLines = true,
    ) {
        // Fail closed on settings that would silently strip real content.
        if ($repeatedLineThreshold <= 0.0 || $repeatedLineThreshold > 1.0) {
            throw new RagException('rag-engine.parsing.pdf.repeated_line_threshold must be greater than 0 and at most 1.');
        }

        if ($repeatedLineEdgeLines < 1) {
            throw new RagException('rag-engine.parsing.pdf.repeated_line_edge_lines must be at least 1.');
        }
    }

    public static function isAvailable(): bool
    {
        return class_exists(SmalotParser::class);
    }

    public function supports(string $mimeType): bool
    {
        return self::isAvailable() && in_array($this->normalize($mimeType), $this->mimeTypes(), true);
    }

    public function parse(string $contents, string $mimeType, array $context = []): ParsedDocument
    {
        if (! self::isAvailable()) {
            throw new ParsingException('PDF parsing requires smalot/pdfparser (composer require smalot/pdfparser).');
        }

        try {
            $pdf = (new SmalotParser)->parseContent($contents);
        } catch (Throwable $e) {
            throw new ParsingException('Could not parse PDF: '.$e->getMessage(), previous: $e);
        }

        $pages = [];
        foreach ($pdf->getPages() as $page) {
            $pages[] = $this->lines($page->getText());
        }

        $pages = $this->cleanPages($pages);

        $sections = [];
        foreach ($pages as $i => $lines) {
            $sections[] = new DocumentSection(type: 'page', content: implode("\n", $lines), page: $i + 1);
        }

        $details = $pdf->getDetails();
        $text = $this->joinPages($pages);
        $ocrUsed = false;

        // Scanned PDF: no (or too little) extractable text → fall back to OCR.
        if (mb_strlen($text) < $this->ocrMinChars
            && $this->ocr instanceof Ocr
            && $this->ocr->supports('application/pdf')) {
            $ocrText = trim($this->ocr->ocr($contents, 'application/pdf'));

            if ($ocrText !== '') {
                $text = $ocrText;
                $sections[] = new DocumentSection(type: 'ocr', content: $ocrText);
                $ocrUsed = true;
            }
        }

        return new ParsedDocument(
            text: $text,
            mimeType: 'application/pdf',
            sections: $sections,
            metadata: array_filter([
                'filename' => $context['filename'] ?? null,
                'page_count' => count($pages),
                'ocr' => $ocrUsed ?: null,
                'title' => is_string($details['Title'] ?? null) ? $details['Title'] : null,
                'author' => is_string($details['Author'] ?? null) ? $details['Author'] : null,
            ]),
        );
    }

    /**
     * Apply the configured clean-up steps to every page.
     *
     * @param  list<list<string>>  $pages  Trimmed, non-empty lines per page.
     * @return list<list<string>>
     */
    private function cleanPages(array $pages): array
    {
        if ($this->stripSymbolLines) {
            $pages = array_map(
                static fn (array $lines): array => array_values(array_filter(
                    $lines,
                    static fn (string $line): bool => preg_match(self::SYMBOL_LINE, $line) !== 1,
                )),
                $pages,
            );
        }

        if ($this->stripRepeatedLines && count($pages) >= max(2, $this->repeatedLineMinPages)) {
            $pages = $this->stripRepeated($pages);
        }

        if ($this->joinWrappedLines) {
            $pages = array_map($this->unwrap(...), $pages);
        }

        return $pages;
    }

    /**
     * @param  list<list<string>>  $pages
     * @return list<list<string>>
     */
    private function stripRepeated(array $pages): array
    {
        $pageCounts = [];

        foreach ($pages as $lines) {
            $keys = [];
            foreach ($this->edgeIndexes(count($lines)) as $index) {
                $keys[$this->lineKey($lines[$index])] = true;
            }

            foreach (array_keys($keys) as $key) {
                $pageCounts[$key] = ($pageCounts[$key] ?? 0) + 1;
            }
        }

        $required = max(2, (int) ceil($this->repeatedLineThreshold * count($pages)));
        $repeated = array_filter($pageCounts, static fn (int $count): bool => $count >= $required);

        if ($repeated === []) {
            return $pages;
        }

        return array_map(function (array $lines) use ($repeated): array {
            foreach ($this->edgeIndexes(count($lines)) as $index) {
                if (isset($repeated[$this->lineKey($lines[$index])])) {
                    unset($lines[$index]);
                }
            }

            return array_values($lines);
        }, $pages);
    }

    /**
     * Indexes of the first and last `repeatedLineEdgeLines` lines of a page.
     *
     * @return list<int>
     */
    private function edgeIndexes(int $count): array
    {
        $edge = $this->repeatedLineEdgeLines;

        if ($count === 0) {
            return [];
        }

        if ($count <= 2 * $edge) {
            return range(0, $count - 1);
        }

        return [...range(0, $edge - 1), ...range($count - $edge, $count - 1)];
    }

    /**
     * Compare lines ignoring digits (page numbers, dates) and spacing.
     */
    private function lineKey(string $line): string
    {
        $key = (string) preg_replace('/\d+/u', '#', mb_strtolower($line));

        return (string) preg_replace('/\s+/u', ' ', $key);
    }

    /**
     * Join visual line wraps inside a page.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function unwrap(array $lines): array
    {
        $result = [];

        foreach ($lines as $line) {
            $last = array_key_last($result);

            if ($last !== null && $this->continues($result[$last], $line)) {
                $result[$last] = $this->glue($result[$last], $line);

                continue;
            }

            $result[] = $line;
        }

        return $result;
    }

    /**
     * Pages are separated by a paragraph break, unless a sentence runs over
     * the page break.
     *
     * @param  list<list<string>>  $pages
     */
    private function joinPages(array $pages): string
    {
        $text = '';
        $previous = null;

        foreach ($pages as $lines) {
            if ($lines === []) {
                continue;
            }

            $page = implode("\n", $lines);

            if ($previous === null) {
                $text = $page;
            } elseif ($this->joinWrappedLines && $this->continues($previous, $lines[0])) {
                $text = $this->glue($text, $page);
            } else {
                $text .= "\n\n".$page;
            }

            $previous = $lines[count($lines) - 1];
        }

        return $text;
    }

    private function continues(string $line, string $next): bool
    {
        return preg_match('/[.!?:;…]["\'”’»)\]]*$/u', $line) !== 1
            && preg_match('/^[\p{Ps}\p{Pi}"\'«“]*\p{Ll}/u', $next) === 1;
    }

    private function glue(string $line, string $next): string
    {
        // Keep a hyphen that ends a line ("e-" + "commerce") without a space.
        return preg_match('/\p{L}-$/u', $line) === 1 ? $line.$next : $line.' '.$next;
    }

    /**
     * @return list<string>
     */
    private function lines(string $text): array
    {
        $lines = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public function mimeTypes(): array
    {
        return ['application/pdf', 'pdf'];
    }

    private function normalize(string $mimeType): string
    {
        return strtolower(trim($mimeType));
    }
}
