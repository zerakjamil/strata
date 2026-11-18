<?php

declare(strict_types=1);

namespace Strata\Engine\Binary;

final class SegmentHeader
{
    public const MAGIC = "STRATA_SEG_V1\0"; // 14 bytes
    public const VERSION = 1;              // uint16
    public const HEADER_SIZE = 128;        // Fixed 128-byte header

    public function __construct(
        public readonly int $docCount,
        public readonly int $deletedCount,
        public readonly int $termCount,
        public readonly int $vectorDimension,
        public readonly int $dictOffset,
        public readonly int $dictLength,
        public readonly int $postingsOffset,
        public readonly int $postingsLength,
        public readonly int $vectorsOffset,
        public readonly int $vectorsLength,
        public readonly int $docStoreOffset,
        public readonly int $docStoreLength,
        public readonly int $tombstoneOffset,
        public readonly int $tombstoneLength,
        public readonly int $version = self::VERSION
    ) {}

    /**
     * Pack the 128-byte binary header.
     */
    public function toBinary(): string
    {
        $bin = self::MAGIC; // 14 bytes
        $bin .= pack('v', $this->version); // 2 bytes -> 16 bytes
        $bin .= pack('V', $this->docCount); // 4 bytes -> 20 bytes
        $bin .= pack('V', $this->deletedCount); // 4 bytes -> 24 bytes
        $bin .= pack('V', $this->termCount); // 4 bytes -> 28 bytes
        $bin .= pack('v', $this->vectorDimension); // 2 bytes -> 30 bytes
        $bin .= pack('v', 0); // Flags (2 bytes) -> 32 bytes
        $bin .= str_repeat("\0", 16); // Padding (16 bytes) -> 48 bytes

        // 5 Sections x 16 bytes (8B offset + 8B length, uint64_le 'P') -> 80 bytes
        $bin .= pack('P', $this->dictOffset);
        $bin .= pack('P', $this->dictLength);

        $bin .= pack('P', $this->postingsOffset);
        $bin .= pack('P', $this->postingsLength);

        $bin .= pack('P', $this->vectorsOffset);
        $bin .= pack('P', $this->vectorsLength);

        $bin .= pack('P', $this->docStoreOffset);
        $bin .= pack('P', $this->docStoreLength);

        $bin .= pack('P', $this->tombstoneOffset);
        $bin .= pack('P', $this->tombstoneLength);

        // Assert exact 128 bytes
        if (strlen($bin) !== self::HEADER_SIZE) {
            throw new \RuntimeException(sprintf(
                'Invalid header size: expected %d bytes, got %d',
                self::HEADER_SIZE,
                strlen($bin)
            ));
        }

        return $bin;
    }

    /**
     * Unpack a 128-byte binary header from raw string.
     */
    public static function fromBinary(string $data): self
    {
        if (strlen($data) < self::HEADER_SIZE) {
            throw new \InvalidArgumentException('Header buffer is smaller than 128 bytes');
        }

        $magic = substr($data, 0, 14);
        if ($magic !== self::MAGIC) {
            throw new \RuntimeException('Invalid Strata segment magic signature');
        }

        $version = unpack('v', substr($data, 14, 2))[1];
        $docCount = unpack('V', substr($data, 16, 4))[1];
        $deletedCount = unpack('V', substr($data, 20, 4))[1];
        $termCount = unpack('V', substr($data, 24, 4))[1];
        $vectorDim = unpack('v', substr($data, 28, 2))[1];

        // Read 5 sections (80 bytes starting at byte 48)
        $dictOffset = unpack('P', substr($data, 48, 8))[1];
        $dictLength = unpack('P', substr($data, 56, 8))[1];

        $postingsOffset = unpack('P', substr($data, 64, 8))[1];
        $postingsLength = unpack('P', substr($data, 72, 8))[1];

        $vectorsOffset = unpack('P', substr($data, 80, 8))[1];
        $vectorsLength = unpack('P', substr($data, 88, 8))[1];

        $docStoreOffset = unpack('P', substr($data, 96, 8))[1];
        $docStoreLength = unpack('P', substr($data, 104, 8))[1];

        $tombstoneOffset = unpack('P', substr($data, 112, 8))[1];
        $tombstoneLength = unpack('P', substr($data, 120, 8))[1];

        return new self(
            docCount: $docCount,
            deletedCount: $deletedCount,
            termCount: $termCount,
            vectorDimension: $vectorDim,
            dictOffset: $dictOffset,
            dictLength: $dictLength,
            postingsOffset: $postingsOffset,
            postingsLength: $postingsLength,
            vectorsOffset: $vectorsOffset,
            vectorsLength: $vectorsLength,
            docStoreOffset: $docStoreOffset,
            docStoreLength: $docStoreLength,
            tombstoneOffset: $tombstoneOffset,
            tombstoneLength: $tombstoneLength,
            version: $version
        );
    }
}
