<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Chunking;

/**
 * Rule-based sentence boundary detection tuned for Italian and English (plus
 * common German abbreviations), used by the sentence and recursive chunkers.
 *
 * A boundary is a run of `.`, `!`, `?` or `…` (optionally followed by closing
 * quotes/brackets) followed by whitespace — unless the context says otherwise:
 *
 * - the word before a single `.` is a known abbreviation (`Sig.`, `Dott.`,
 *   `ecc.`, `Mr.`), a dotted form (`S.r.l.`, `S.p.A.`, `e.g.`, `i.e.`) or a
 *   single-letter initial (`J. Smith`, `N. preventivo`);
 * - the word is a reference abbreviation followed by a number (`art. 5`,
 *   `No. 3`, `pag. 12`);
 * - the word is a list number at the start of a line (`1. Primo punto`);
 * - the next character is lowercase (`ecc. e altro`, `Wait... what`).
 *
 * Decimals, thousands and dates (`3.5`, `€ 8.400,00`, `17.09.2026`), URLs and
 * e-mail addresses never contain "terminator + whitespace", so they are never
 * split. Blank lines (paragraph breaks) and line-leading bullets (`•`, `-`,
 * `1)`) are always boundaries. A single line break is ordinary whitespace:
 * extracted text (PDF especially) wraps sentences across lines.
 */
final class SentenceSplitter
{
    /**
     * Words that are always abbreviations when followed by a dot (lowercase,
     * without the dot).
     *
     * @var list<string>
     */
    public const ABBREVIATIONS = [
        // Italian titles and common forms.
        'sig', 'sigg', 'sigra', 'dott', 'dottssa', 'dr', 'prof', 'profssa', 'ing', 'avv', 'arch', 'geom',
        'rag', 'egr', 'gent', 'gentmo', 'spett', 'spettle', 'on', 'mons', 'sen',
        'ecc', 'es', 'ca', 'cfr', 'pagg', 'artt', 'nn', 'tel', 'cell', 'fax', 'rif', 'all', 'sez',
        'lett', 'cod', 'prot', 'reg', 'tot', 'fatt', 'mln', 'mld', 'sgg', 'segg', 'ss', 'vd',
        // English.
        'mr', 'mrs', 'ms', 'mx', 'sr', 'jr', 'st', 'vs', 'etc', 'inc', 'ltd', 'corp', 'approx',
        'dept', 'est', 'govt', 'misc', 'ave', 'blvd', 'mt', 'ft',
        // German.
        'bzw', 'usw', 'evtl', 'ggf', 'inkl', 'zzgl', 'vgl', 'bspw', 'str',
    ];

    /**
     * Words that are abbreviations only when a number follows (`art. 5`,
     * `No. 3`) — elsewhere they may legitimately end a sentence.
     *
     * @var list<string>
     */
    public const NUMERIC_ABBREVIATIONS = [
        'art', 'pag', 'pp', 'no', 'nos', 'nr', 'num', 'n', 'fig', 'figs', 'vol', 'voll', 'cap',
        'tab', 'par', 'p', 'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sep', 'sept', 'oct',
        'nov', 'dec', 'ott', 'dic', 'gen', 'giu', 'lug', 'ago', 'set',
    ];

    /** @var array<string, true> */
    private readonly array $abbreviations;

    /** @var array<string, true> */
    private readonly array $numericAbbreviations;

    /**
     * @param  list<string>  $extraAbbreviations  Additional always-abbreviations (with or without the dot, any case).
     */
    public function __construct(array $extraAbbreviations = [])
    {
        $extra = array_values(array_filter(
            array_map(self::normalizeAbbreviation(...), $extraAbbreviations),
            static fn (string $abbreviation): bool => $abbreviation !== '',
        ));

        $this->abbreviations = array_fill_keys([...self::ABBREVIATIONS, ...$extra], true);
        $this->numericAbbreviations = array_fill_keys(self::NUMERIC_ABBREVIATIONS, true);
    }

    /**
     * Split text into trimmed sentences with their character offsets.
     *
     * @return list<array{text: string, offset: int}>
     */
    public function split(string $text): array
    {
        $offsets = new OffsetMap($text);
        $sentences = [];

        foreach ($this->spans($text) as [$start, $end]) {
            $sentences[] = [
                'text' => substr($text, $start, $end - $start),
                'offset' => $offsets->charOffset($start),
            ];
        }

        return $sentences;
    }

    /**
     * Sentence spans as trimmed, non-empty BYTE ranges [start, end) of $text,
     * in order. Byte ranges let callers slice the source without copying it.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function spans(string $text, int $from = 0, ?int $to = null): array
    {
        $to ??= strlen($text);
        $slice = substr($text, $from, $to - $from);

        if (trim($slice) === '') {
            return [];
        }

        $spans = [];
        $start = 0;

        foreach ($this->boundaries($slice) as [$end, $next]) {
            if ($end > $start) {
                $span = self::trimRange($slice, $start, $end);

                if ($span !== null) {
                    $spans[] = [$span[0] + $from, $span[1] + $from];
                }
            }

            $start = max($start, $next);
        }

        $last = self::trimRange($slice, $start, strlen($slice));

        if ($last !== null) {
            $spans[] = [$last[0] + $from, $last[1] + $from];
        }

        return $spans;
    }

    /**
     * Shrink [start, end) so it neither starts nor ends with whitespace.
     *
     * @return array{0: int, 1: int}|null Null when the range is blank.
     */
    public static function trimRange(string $text, int $start, int $end): ?array
    {
        while ($start < $end && ctype_space($text[$start])) {
            $start++;
        }

        while ($end > $start && ctype_space($text[$end - 1])) {
            $end--;
        }

        return $end > $start ? [$start, $end] : null;
    }

    /**
     * Boundaries as [end of sentence, start of the next] byte pairs, sorted.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function boundaries(string $text): array
    {
        $boundaries = [];

        // Paragraph breaks (a blank line) are always boundaries.
        preg_match_all('/\n[ \t]*\n\s*/u', $text, $paragraphs, PREG_OFFSET_CAPTURE);
        foreach ($paragraphs[0] as [$match, $position]) {
            $boundaries[] = [$position, $position + strlen($match)];
        }

        // A line starting with a bullet or a list number starts a new sentence.
        preg_match_all('/\n(?=[ \t]*(?:[•◦‣▪∙·*\-–—]|\d{1,3}[.)])[ \t])/u', $text, $bullets, PREG_OFFSET_CAPTURE);
        foreach ($bullets[0] as [, $position]) {
            $boundaries[] = [$position, $position + 1];
        }

        // Terminators followed by whitespace, with the word before them and
        // the first character after them.
        preg_match_all(
            '/(\S*?)([.!?…]+)(["\'”’»)\]]*)(\s+)(?=(\S))/u',
            $text,
            $candidates,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($candidates as $candidate) {
            [$word, $wordOffset] = $candidate[1];
            $terminator = $candidate[2][0];
            [$space, $spaceOffset] = $candidate[4];
            $next = $candidate[5][0];

            if ($this->isBoundary($text, $word, $wordOffset, $terminator, $next)) {
                $boundaries[] = [$spaceOffset, $spaceOffset + strlen($space)];
            }
        }

        usort($boundaries, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        return $boundaries;
    }

    private function isBoundary(string $text, string $word, int $wordOffset, string $terminator, string $next): bool
    {
        // "ecc. e altro", "Wait... what", "Yahoo! is": the sentence goes on.
        if (preg_match('/^\p{Ll}/u', $next) === 1) {
            return false;
        }

        if ($terminator !== '.') {
            return true;
        }

        $bare = (string) preg_replace('/^[\p{Ps}\p{Pi}"\'«“‘]+/u', '', $word);

        // Single-letter initials: "J. Smith", "N. preventivo".
        if (preg_match('/^\p{L}$/u', $bare) === 1) {
            return false;
        }

        // Dotted abbreviations and legal forms: "S.r.l.", "S.p.A.", "e.g.", "P.IVA".
        if (preg_match('/^(?:\p{L}{1,5}\.)+\p{L}{1,5}$/u', $bare) === 1) {
            return false;
        }

        $key = self::normalizeAbbreviation($bare);

        if (isset($this->abbreviations[$key])) {
            return false;
        }

        if (isset($this->numericAbbreviations[$key]) && preg_match('/^\d/', $next) === 1) {
            return false;
        }

        // "1. Primo punto" at the start of a line is a list marker.
        if (preg_match('/^\d{1,3}$/', $bare) === 1) {
            $bareOffset = $wordOffset + strlen($word) - strlen($bare);
            $before = $bareOffset === 0 ? "\n" : $text[$bareOffset - 1];

            if ($before === "\n") {
                return false;
            }
        }

        return true;
    }

    private static function normalizeAbbreviation(string $abbreviation): string
    {
        return mb_strtolower(str_replace('.', '', trim($abbreviation)));
    }
}
