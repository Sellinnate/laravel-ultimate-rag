<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Tests\Support;

/**
 * Builds small, valid text PDFs in memory for parser tests — no binary
 * fixture to commit, and every case states its page content inline.
 *
 * Each page is a list of body lines written top-down (one text line per
 * entry) plus an optional footer line at the bottom of the page. A footer may
 * contain a "\t", which is rendered as a jump to the right margin (like a page
 * number in a word-processor footer). Lines representable in Windows-1252
 * (€, à, è, •) use Helvetica with WinAnsi encoding; any other line (arrows,
 * box drawing…) uses a second font whose ToUnicode CMap maps one-byte codes
 * to the exact characters, as real PDF producers do.
 */
final class PdfBuilder
{
    /** @var list<array{lines: list<string>, footer: ?string, header: ?string}> */
    private array $pages = [];

    private ?string $title = null;

    /** @var array<string, int> Character → one-byte code in the mapped font. */
    private array $mapped = [];

    public static function make(): self
    {
        return new self;
    }

    /**
     * @param  list<string>  $lines
     */
    public function page(array $lines, ?string $footer = null, ?string $header = null): self
    {
        $this->pages[] = ['lines' => $lines, 'footer' => $footer, 'header' => $header];

        return $this;
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function build(): string
    {
        $objects = [];
        $pageCount = count($this->pages);
        $fontId = 3;
        $firstPageId = 4;
        $kids = [];

        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = ($firstPageId + 2 * $i).' 0 R';
        }

        $mappedFontId = $firstPageId + 2 * $pageCount;
        $cmapId = $mappedFontId + 1;

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pageCount.' >>';
        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

        foreach ($this->pages as $i => $page) {
            $pageId = $firstPageId + 2 * $i;
            $contentId = $pageId + 1;
            $stream = $this->stream($page);

            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                .'/Resources << /Font << /F1 '.$fontId.' 0 R /F2 '.$mappedFontId.' 0 R >> >> /Contents '.$contentId.' 0 R >>';
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
        }

        $cmap = $this->toUnicode();
        $objects[$mappedFontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /ToUnicode '.$cmapId.' 0 R >>';
        $objects[$cmapId] = '<< /Length '.strlen($cmap)." >>\nstream\n".$cmap."\nendstream";

        $infoId = null;
        if ($this->title !== null) {
            $infoId = $cmapId + 1;
            $objects[$infoId] = '<< /Title ('.$this->escape($this->title).') >>';
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$body."\nendobj\n";
        }

        $size = max(array_keys($objects)) + 1;
        $xref = strlen($pdf);
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";

        for ($id = 1; $id < $size; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }

        $pdf .= "trailer\n<< /Size {$size} /Root 1 0 R"
            .($infoId !== null ? " /Info {$infoId} 0 R" : '')
            ." >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }

    /**
     * @param  array{lines: list<string>, footer: ?string, header: ?string}  $page
     */
    private function stream(array $page): string
    {
        $ops = [];

        if ($page['header'] !== null) {
            $ops[] = $this->line($page['header'], 56, 810);
        }

        $ops[] = 'BT 14 TL 56 780 Td';
        foreach ($page['lines'] as $index => $line) {
            $ops[] = ($index === 0 ? '' : 'T* ').$this->show($line, 11);
        }
        $ops[] = 'ET';

        if ($page['footer'] !== null) {
            $ops[] = $this->line($page['footer'], 56, 30);
        }

        return implode("\n", $ops);
    }

    private function line(string $text, int $x, int $y): string
    {
        $parts = explode("\t", $text, 2);
        $op = "BT {$x} {$y} Td ".$this->show($parts[0], 9);

        if (isset($parts[1])) {
            $op .= ' 440 0 Td '.$this->show($parts[1], 9);
        }

        return $op.' ET';
    }

    /**
     * A font selection plus a Tj showing $text.
     */
    private function show(string $text, int $size): string
    {
        $encoded = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        if (mb_convert_encoding($encoded, 'UTF-8', 'Windows-1252') === $text) {
            return "/F1 {$size} Tf (".$this->escape($text).') Tj';
        }

        $hex = '';
        foreach (mb_str_split($text) as $char) {
            $this->mapped[$char] ??= count($this->mapped) + 1;
            $hex .= sprintf('%02X', $this->mapped[$char]);
        }

        return "/F2 {$size} Tf <{$hex}> Tj";
    }

    private function toUnicode(): string
    {
        $entries = [];
        foreach ($this->mapped as $char => $code) {
            $utf16 = strtoupper(bin2hex((string) mb_convert_encoding($char, 'UTF-16BE', 'UTF-8')));
            $entries[] = sprintf('<%02X> <%s>', $code, $utf16);
        }

        $body = '';
        foreach (array_chunk($entries, 100) as $block) {
            $body .= count($block)." beginbfchar\n".implode("\n", $block)."\nendbfchar\n";
        }

        return "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            ."/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            ."/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            ."1 begincodespacerange\n<00> <FF>\nendcodespacerange\n"
            .$body
            ."endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
    }

    private function escape(string $text): string
    {
        $encoded = (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
    }
}
