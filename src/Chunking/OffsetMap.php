<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Chunking;

/**
 * Converts byte offsets of a UTF-8 string into character offsets.
 *
 * Chunkers work on byte ranges (cheap slicing of large texts) but report
 * character offsets. Lookups are incremental: requests in increasing order cost
 * O(distance), so converting every chunk start of a document stays linear.
 *
 * @internal
 */
final class OffsetMap
{
    private int $byte = 0;

    private int $char = 0;

    public function __construct(private readonly string $text) {}

    private int $reverseByte = 0;

    private int $reverseChar = 0;

    /**
     * The byte offset of a character offset (the inverse of {@see charOffset()}),
     * also incremental for increasing requests.
     */
    public function byteOffset(int $charOffset): int
    {
        if ($charOffset < $this->reverseChar) {
            $this->reverseByte = 0;
            $this->reverseChar = 0;
        }

        $length = strlen($this->text);

        while ($this->reverseChar < $charOffset && $this->reverseByte < $length) {
            $lead = ord($this->text[$this->reverseByte]);
            $this->reverseByte += match (true) {
                $lead >= 0xF0 => 4,
                $lead >= 0xE0 => 3,
                $lead >= 0xC0 => 2,
                default => 1,
            };
            $this->reverseChar++;
        }

        return min($this->reverseByte, $length);
    }

    public function charOffset(int $byteOffset): int
    {
        if ($byteOffset < $this->byte) {
            $this->byte = 0;
            $this->char = 0;
        }

        $this->char += mb_strlen(substr($this->text, $this->byte, $byteOffset - $this->byte), 'UTF-8');
        $this->byte = $byteOffset;

        return $this->char;
    }
}
