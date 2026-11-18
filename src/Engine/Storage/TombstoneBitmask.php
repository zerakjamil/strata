<?php

declare(strict_types=1);

namespace Strata\Engine\Storage;

final class TombstoneBitmask
{
    private string $bitmask;
    private readonly int $capacity;

    public function __construct(int $capacity, string $bitmask = '')
    {
        $this->capacity = $capacity;
        $byteLength = (int) ceil($capacity / 8);

        if ($bitmask === '') {
            $this->bitmask = str_repeat("\0", $byteLength);
        } else {
            $this->bitmask = str_pad(substr($bitmask, 0, $byteLength), $byteLength, "\0");
        }
    }

    /**
     * Mark a document ID as deleted (tombstoned).
     */
    public function markDeleted(int $docId): void
    {
        if ($docId < 0 || $docId >= $this->capacity) {
            return;
        }

        $byteIdx = $docId >> 3; // $docId / 8
        $bitIdx = $docId & 7;   // $docId % 8

        $current = ord($this->bitmask[$byteIdx]);
        $this->bitmask[$byteIdx] = chr($current | (1 << $bitIdx));
    }

    /**
     * Check if a document ID is marked as deleted.
     */
    public function isDeleted(int $docId): bool
    {
        if ($docId < 0 || $docId >= $this->capacity) {
            return true;
        }

        $byteIdx = $docId >> 3;
        $bitIdx = $docId & 7;

        return (ord($this->bitmask[$byteIdx]) & (1 << $bitIdx)) !== 0;
    }

    /**
     * Get the raw binary bitmask string.
     */
    public function toBinary(): string
    {
        return $this->bitmask;
    }

    /**
     * Count total deleted documents in the mask.
     */
    public function deletedCount(): int
    {
        $count = 0;
        $len = strlen($this->bitmask);
        for ($i = 0; $i < $len; $i++) {
            $byte = ord($this->bitmask[$i]);
            // Popcount 8-bit
            while ($byte > 0) {
                $count += ($byte & 1);
                $byte >>= 1;
            }
        }

        return $count;
    }
}
