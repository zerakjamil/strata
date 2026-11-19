<?php

declare(strict_types=1);

namespace Strata\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Strata\Engine\Binary\SegmentReader;
use Strata\Engine\Binary\SegmentWriter;

final class BlockMaxWandTest extends TestCase
{
    public function testBlockMaxWandProvidesExactTopKRankingWithoutEarlyLoss(): void
    {
        $tempSeg = sys_get_temp_dir() . '/bmw_' . uniqid() . '.seg';

        // Create 20 documents with varied keyword density
        $documents = [];
        for ($i = 1; $i <= 50; $i++) {
            if ($i === 45) {
                // Document 45 has maximum keyword frequency (highest possible relevance)
                $text = 'mechanical mechanical mechanical keyboard keyboard keyboard pro master';
            } elseif ($i % 2 === 0) {
                $text = 'mechanical standard office device';
            } else {
                $text = 'keyboard standard wireless peripheral';
            }

            $documents[] = [
                'id' => $i,
                'text' => $text,
                'payload' => ['id' => $i, 'title' => $text],
            ];
        }

        $writer = new SegmentWriter();
        $writer->write($tempSeg, $documents);

        $reader = SegmentReader::open($tempSeg);
        $results = $reader->search(
            query: 'mechanical keyboard',
            limit: 5,
            fastPath: true
        );

        $this->assertNotEmpty($results);
        // The highest-ranked document MUST be document 45 because it contains 3x mechanical and 3x keyboard
        $this->assertEquals(45, $results[0]['id'], 'Block-Max WAND must not prune the highest-scoring candidate (doc 45)');

        unset($reader);
        @unlink($tempSeg);
        @unlink(substr($tempSeg, 0, -4) . '.del');
    }
}
