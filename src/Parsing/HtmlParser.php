<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Parsing;

use DOMDocument;
use DOMNode;
use Sellinnate\RagEngine\Contracts\Parser;
use Sellinnate\RagEngine\Data\DocumentSection;
use Sellinnate\RagEngine\Data\ParsedDocument;

/**
 * HTML parser (FR-PA-06). Sanitizes markup: script/style are dropped, the DOM
 * is loaded with no network access (LIBXML_NONET) and no external entity
 * resolution (FR-SEC-08). Headings become logical sections (FR-PA-10), and
 * block elements become paragraphs / lines in the text so chunkers can split
 * on them.
 */
final class HtmlParser implements Parser
{
    /** Elements rendered as their own paragraph. */
    private const BLOCK_ELEMENTS = [
        'address', 'article', 'aside', 'blockquote', 'details', 'dialog', 'div', 'dl', 'fieldset',
        'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header',
        'hgroup', 'hr', 'main', 'nav', 'ol', 'p', 'section', 'summary', 'table', 'ul',
        'caption', 'thead', 'tbody', 'tfoot',
    ];

    /** Elements rendered on their own line. */
    private const LINE_ELEMENTS = ['li', 'tr', 'dt', 'dd', 'option'];

    /** Table cells, separated by a tab. */
    private const CELL_ELEMENTS = ['td', 'th'];

    /** Private-use markers for a paragraph / line break while walking the DOM. */
    private const PARAGRAPH = "\u{E000}";

    private const LINE = "\u{E001}";

    public function supports(string $mimeType): bool
    {
        return in_array($this->normalize($mimeType), $this->mimeTypes(), true);
    }

    public function parse(string $contents, string $mimeType, array $context = []): ParsedDocument
    {
        $dom = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        // LIBXML_NONET blocks network fetches; HTML parsing does not expand
        // external entities, but we keep the hardened flags for defence in depth.
        $dom->loadHTML(
            '<?xml encoding="UTF-8">'.$contents,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->removeNodes($dom, ['script', 'style', 'noscript']);

        $sections = [];
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $level => $tag) {
            foreach ($dom->getElementsByTagName($tag) as $node) {
                $sections[] = new DocumentSection(
                    type: 'heading',
                    content: trim($node->textContent),
                    level: $level + 1,
                );
            }
        }

        $title = $this->firstTagText($dom, 'title');
        $body = $dom->getElementsByTagName('body')->item(0);
        // DOMDocument::loadHTML always wraps content in a body element.
        $text = $this->normalizeWhitespace($this->textOf($body instanceof DOMNode ? $body : $dom));

        return new ParsedDocument(
            text: $text,
            mimeType: 'text/html',
            sections: $sections,
            metadata: array_filter([
                'filename' => $context['filename'] ?? null,
                'title' => $title,
            ]),
        );
    }

    public function mimeTypes(): array
    {
        return ['text/html', 'application/xhtml+xml', 'html', 'htm'];
    }

    /**
     * @param  list<string>  $tags
     */
    private function removeNodes(DOMDocument $dom, array $tags): void
    {
        foreach ($tags as $tag) {
            $nodes = iterator_to_array($dom->getElementsByTagName($tag));

            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    private function firstTagText(DOMDocument $dom, string $tag): ?string
    {
        $node = $dom->getElementsByTagName($tag)->item(0);
        $text = $node instanceof DOMNode ? trim($node->textContent) : '';

        return $text === '' ? null : $text;
    }

    /**
     * Visible text with the document's structure: block elements (paragraphs,
     * headings, tables, lists…) become paragraphs, `<br>`, list items and
     * table rows become lines, table cells are tab-separated, and `<pre>`
     * keeps its own line breaks. Source-code whitespace is collapsed, as a
     * browser would render it.
     */
    private function textOf(DOMNode $node): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $text .= (string) preg_replace(['/[\x{E000}\x{E001}]/u', '/\s+/u'], ['', ' '], (string) $child->nodeValue);

                continue;
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            $text .= match (true) {
                $tag === 'br' => self::LINE,
                $tag === 'pre' => self::PARAGRAPH.$this->preserveLines((string) $child->textContent).self::PARAGRAPH,
                in_array($tag, self::LINE_ELEMENTS, true) => self::LINE.$this->textOf($child).self::LINE,
                in_array($tag, self::CELL_ELEMENTS, true) => "\t".$this->textOf($child),
                in_array($tag, self::BLOCK_ELEMENTS, true) => self::PARAGRAPH.$this->textOf($child).self::PARAGRAPH,
                default => $this->textOf($child),
            };
        }

        return $text;
    }

    private function preserveLines(string $text): string
    {
        $text = (string) preg_replace('/[\x{E000}\x{E001}]/u', '', $text);

        // Inside <pre> every line break is a real one.
        return str_replace(["\r\n", "\r", "\n"], self::LINE, $text);
    }

    private function normalizeWhitespace(string $text): string
    {
        // Whitespace runs → single space (a tab between cells stays a tab).
        $text = preg_replace('/ *\t[\t ]*/u', "\t", $text) ?? $text;
        $text = preg_replace('/[^\S\t]+/u', ' ', $text) ?? $text;

        // Structural markers (and the whitespace around them) → a paragraph
        // break when any block boundary is involved, else a line break.
        $text = preg_replace_callback(
            '/[\s\x{E000}\x{E001}]*[\x{E000}\x{E001}][\s\x{E000}\x{E001}]*/u',
            static fn (array $match): string => str_contains($match[0], self::PARAGRAPH) ? "\n\n" : "\n",
            $text,
        ) ?? $text;

        // Drop a cell separator at the start or end of a line.
        $text = preg_replace('/^\t+|\t+$/mu', '', $text) ?? $text;

        return trim($text);
    }

    private function normalize(string $mimeType): string
    {
        return strtolower(trim($mimeType));
    }
}
