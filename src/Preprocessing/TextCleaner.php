<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Preprocessing;

use Sellinnate\RagEngine\Contracts\PreprocessingStage;
use Sellinnate\RagEngine\Data\ParsedDocument;

/**
 * Text cleaning stage (FR-PP-01): normalizes encoding to valid UTF-8, collapses
 * runs of spaces, strips control characters and common extraction artefacts,
 * and trims. Non-destructive of meaningful content: line breaks and paragraph
 * breaks (one blank line) are preserved so chunkers can split on them.
 */
final class TextCleaner implements PreprocessingStage
{
    public function process(ParsedDocument $document): ParsedDocument
    {
        return $document->withText($this->clean($document->text));
    }

    public function clean(string $text): string
    {
        // Force valid UTF-8 (drop invalid byte sequences from extraction).
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Normalize line endings.
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Strip control chars except tab/newline.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;

        // Remove soft hyphens and zero-width characters (common PDF artefacts).
        $text = preg_replace('/[\x{00AD}\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;

        // Collapse runs of spaces/tabs.
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;

        // Trim trailing spaces on each line.
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;

        // Collapse 2+ blank lines to a single paragraph break. Line and
        // paragraph breaks are otherwise kept: chunkers split on them.
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    public function name(): string
    {
        return 'text-cleaner';
    }
}
