<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Chunking;

/**
 * Shared engine behind the recursive and sentence chunkers: breaks text into
 * pieces along a hierarchy of boundaries, then packs adjacent pieces into
 * chunks of at most `size` characters with an overlap tail.
 *
 * - A piece that fits in `size` is never split further, so chunk boundaries
 *   fall on the coarsest boundary that works. Pieces that are too long are
 *   split at the next level; characters are the last resort.
 * - A chunk is the exact source slice from its first to its last piece, so
 *   paragraph and line breaks inside it survive. Offsets are characters.
 * - The overlap (in characters) repeats whole trailing pieces of the previous
 *   chunk; when the last piece is too long, its trailing whole sentences are
 *   repeated instead. The overlap never pushes a chunk past `size`.
 *
 * Internally everything is a byte range of the source text, so large
 * documents are sliced without copying.
 *
 * @internal
 */
final class TextPacker
{
    public const PARAGRAPH = 'paragraph';

    public const SENTENCE = 'sentence';

    public const LINE = 'line';

    public const WORD = 'word';

    public function __construct(private readonly SentenceSplitter $sentences) {}

    /**
     * @param  list<self::PARAGRAPH|self::SENTENCE|self::LINE|self::WORD>  $levels  Boundaries to try, coarsest first (characters are always the final fallback).
     * @return list<array{text: string, offset: int}>
     */
    public function pack(string $text, array $levels, int $size, int $overlap): array
    {
        $size = max(1, $size);
        $overlap = max(0, min($overlap, $size - 1));

        if (trim($text) === '') {
            return [];
        }

        $pieces = $this->atomize($text, 0, strlen($text), $levels, $size);
        $offsets = new OffsetMap($text);

        $chunks = [];
        foreach ($this->merge($text, $pieces, $size, $overlap) as [$start, $end]) {
            $chunks[] = [
                'text' => substr($text, $start, $end - $start),
                'offset' => $offsets->charOffset($start),
            ];
        }

        return $chunks;
    }

    /**
     * Break [start, end) into trimmed byte ranges no longer than $size chars.
     *
     * @param  list<self::PARAGRAPH|self::SENTENCE|self::LINE|self::WORD>  $levels
     * @param  positive-int  $size
     * @return list<array{0: int, 1: int}>
     */
    private function atomize(string $text, int $start, int $end, array $levels, int $size): array
    {
        if ($levels === []) {
            return $this->splitCharacters($text, $start, $end, $size);
        }

        $level = $levels[0];
        $finer = array_slice($levels, 1);
        $atoms = [];

        foreach ($this->pieces($text, $start, $end, $level) as $piece) {
            if ($this->length($text, $piece[0], $piece[1]) <= $size) {
                $atoms[] = $piece;

                continue;
            }

            array_push($atoms, ...$this->atomize($text, $piece[0], $piece[1], $finer, $size));
        }

        return $atoms;
    }

    /**
     * @param  self::PARAGRAPH|self::SENTENCE|self::LINE|self::WORD  $level
     * @return list<array{0: int, 1: int}>
     */
    private function pieces(string $text, int $start, int $end, string $level): array
    {
        return match ($level) {
            self::PARAGRAPH => $this->splitOn('/\R\h*\R/u', $text, $start, $end),
            self::SENTENCE => $this->sentences->spans($text, $start, $end),
            self::LINE => $this->splitOn('/\R/u', $text, $start, $end),
            self::WORD => $this->splitOn('/\s+/u', $text, $start, $end),
        };
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function splitOn(string $pattern, string $text, int $start, int $end): array
    {
        $slice = substr($text, $start, $end - $start);
        preg_match_all($pattern, $slice, $matches, PREG_OFFSET_CAPTURE);

        $ranges = [];
        $cursor = 0;

        foreach ($matches[0] as [$separator, $position]) {
            $ranges[] = [$cursor, $position];
            $cursor = $position + strlen($separator);
        }
        $ranges[] = [$cursor, strlen($slice)];

        return $this->trimmed($slice, $ranges, $start);
    }

    /**
     * @param  positive-int  $size
     * @return list<array{0: int, 1: int}>
     */
    private function splitCharacters(string $text, int $start, int $end, int $size): array
    {
        $slice = substr($text, $start, $end - $start);

        $ranges = [];
        $cursor = 0;

        foreach (mb_str_split($slice, $size) as $part) {
            $ranges[] = [$cursor, $cursor + strlen($part)];
            $cursor += strlen($part);
        }

        return $this->trimmed($slice, $ranges, $start);
    }

    /**
     * @param  list<array{0: int, 1: int}>  $ranges  Ranges relative to $slice.
     * @return list<array{0: int, 1: int}> Non-blank ranges, trimmed and shifted by $base.
     */
    private function trimmed(string $slice, array $ranges, int $base): array
    {
        $result = [];

        foreach ($ranges as [$start, $end]) {
            $range = SentenceSplitter::trimRange($slice, $start, $end);

            if ($range !== null) {
                $result[] = [$range[0] + $base, $range[1] + $base];
            }
        }

        return $result;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $pieces
     * @return list<array{0: int, 1: int}>
     */
    private function merge(string $text, array $pieces, int $size, int $overlap): array
    {
        $chunks = [];
        $window = [];
        $windowLength = 0;

        foreach ($pieces as $piece) {
            if ($window === []) {
                $window = [$piece];
                $windowLength = $this->length($text, $piece[0], $piece[1]);

                continue;
            }

            $last = $window[count($window) - 1];
            $added = $this->length($text, $last[1], $piece[1]);

            if ($windowLength + $added <= $size) {
                $window[] = $piece;
                $windowLength += $added;

                continue;
            }

            $chunks[] = [$window[0][0], $last[1]];
            $window = $this->overlapTail($text, $window, $overlap);

            // The tail must leave room for the new piece.
            while ($window !== [] && $this->length($text, $window[0][0], $piece[1]) > $size) {
                array_shift($window);
            }

            $window[] = $piece;
            $windowLength = $this->length($text, $window[0][0], $piece[1]);
        }

        if ($window !== []) {
            $chunks[] = [$window[0][0], $window[count($window) - 1][1]];
        }

        return $chunks;
    }

    /**
     * @param  non-empty-list<array{0: int, 1: int}>  $window
     * @return list<array{0: int, 1: int}>
     */
    private function overlapTail(string $text, array $window, int $overlap): array
    {
        if ($overlap === 0) {
            return [];
        }

        $end = $window[count($window) - 1][1];
        $tail = [];
        $boundary = $end;
        $length = 0;

        for ($i = count($window) - 1; $i >= 0; $i--) {
            [$start, $pieceEnd] = $window[$i];
            $candidate = $length + $this->length($text, $start, $boundary);

            if ($candidate <= $overlap) {
                array_unshift($tail, $window[$i]);
                $length = $candidate;
                $boundary = $start;

                continue;
            }

            // Too long as a whole: repeat its trailing whole sentences instead.
            $sentences = $this->sentences->spans($text, $start, $pieceEnd);

            for ($j = count($sentences) - 1; $j >= 1; $j--) {
                if ($this->length($text, $sentences[$j][0], $end) > $overlap) {
                    break;
                }

                array_unshift($tail, $sentences[$j]);
            }

            break;
        }

        return $tail;
    }

    private function length(string $text, int $start, int $end): int
    {
        return $end > $start ? mb_strlen(substr($text, $start, $end - $start)) : 0;
    }
}
