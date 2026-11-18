<?php

declare(strict_types=1);

namespace Strata\Engine\Binary;

final class Manifest
{
    /**
     * @param array<int, array{
     *     path: string,
     *     doc_count: int,
     *     level: int,
     *     created_at: int
     * }> $segments
     * @param int $totalDocs
     * @param int $generation
     */
    public function __construct(
        public array $segments = [],
        public int $totalDocs = 0,
        public int $generation = 1
    ) {}

    /**
     * Load manifest from storage path.
     */
    public static function load(string $manifestPath): self
    {
        if (!file_exists($manifestPath)) {
            return new self();
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            return new self();
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return new self();
        }

        return new self(
            segments: $data['segments'] ?? [],
            totalDocs: (int)($data['total_docs'] ?? 0),
            generation: (int)($data['generation'] ?? 1)
        );
    }

    /**
     * Save manifest atomically to path.
     */
    public function save(string $manifestPath): void
    {
        $this->generation++;
        $dir = dirname($manifestPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode([
            'version' => 1,
            'generation' => $this->generation,
            'total_docs' => $this->totalDocs,
            'segments' => $this->segments,
            'updated_at' => time(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $temp = $manifestPath . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($temp, $json);
        rename($temp, $manifestPath);
    }

    /**
     * Register a new segment file in the manifest.
     */
    public function addSegment(string $segmentPath, int $docCount, int $level = 0): void
    {
        $this->segments[] = [
            'path' => $segmentPath,
            'doc_count' => $docCount,
            'level' => $level,
            'created_at' => time(),
        ];
        $this->totalDocs += $docCount;
    }
}
