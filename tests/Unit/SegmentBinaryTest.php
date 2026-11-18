<?php

declare(strict_types=1);

namespace Strata\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Strata\Engine\Binary\SegmentReader;
use Strata\Engine\Binary\SegmentWriter;
use Strata\Engine\Vector\ScalarQuantizer;

final class SegmentBinaryTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . '/strata_test_' . bin2hex(random_bytes(4)) . '.seg';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testWriteAndSearchSegment(): void
    {
        $writer = new SegmentWriter();
        $quantizer = new ScalarQuantizer();

        $docs = [
            [
                'id' => 1,
                'text' => 'Ergonomic wireless mechanical keyboard with blue switches',
                'vector' => array_pad([0.8, 0.2], 384, 0.0),
                'filters' => ['status' => 'active', 'in_stock' => true],
                'payload' => ['id' => 1, 'title' => 'Mechanical Keyboard', 'price' => 129.99],
            ],
            [
                'id' => 2,
                'text' => 'Ultra high precision optical gaming mouse with rgb lighting',
                'vector' => array_pad([0.1, 0.9], 384, 0.0),
                'filters' => ['status' => 'active', 'in_stock' => false],
                'payload' => ['id' => 2, 'title' => 'Gaming Mouse', 'price' => 59.99],
            ],
            [
                'id' => 3,
                'text' => 'Adjustable ergonomic office chair with lumbar support',
                'vector' => array_pad([0.4, 0.4], 384, 0.0),
                'filters' => ['status' => 'inactive', 'in_stock' => true],
                'payload' => ['id' => 3, 'title' => 'Ergonomic Chair', 'price' => 249.99],
            ],
        ];

        $header = $writer->write($this->tempFile, $docs, 384);
        $this->assertSame(3, $header->docCount);

        $reader = SegmentReader::open($this->tempFile);

        // 1. Lexical BM25 search
        $results = $reader->search(query: 'mechanical keyboard');
        $this->assertNotEmpty($results);
        $this->assertSame('Mechanical Keyboard', $results[0]['title']);

        // 2. Filter testing: 'in_stock' => true
        $filteredResults = $reader->search(
            query: 'ergonomic',
            filters: ['in_stock' => true]
        );
        // Should find doc 1 and doc 3, but not doc 2
        $titles = array_column($filteredResults, 'title');
        $this->assertContains('Mechanical Keyboard', $titles);
        $this->assertContains('Ergonomic Chair', $titles);
        $this->assertNotContains('Gaming Mouse', $titles);

        // 3. Fast-Path payload projection
        $this->assertSame(129.99, $results[0]['price']);
        $this->assertArrayHasKey('_score', $results[0]);
    }
}
