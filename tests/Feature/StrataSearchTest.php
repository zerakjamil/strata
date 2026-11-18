<?php

declare(strict_types=1);

namespace Strata\Tests\Feature;

use Strata\Engine\Binary\SegmentWriter;
use Strata\Facades\Strata;
use Strata\Tests\TestCase;

final class StrataSearchTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDir = sys_get_temp_dir() . '/strata_feature_' . bin2hex(random_bytes(4));
        mkdir($this->storageDir, 0755, true);

        config(['strata.path' => $this->storageDir]);

        // Create a test segment and manifest
        $writer = new SegmentWriter();
        $segFile = $this->storageDir . '/segment_001.seg';
        $writer->write($segFile, [
            [
                'id' => 101,
                'text' => 'High performance wireless developer mechanical keyboard',
                'payload' => ['id' => 101, 'title' => 'Dev Keyboard', 'slug' => 'dev-kb', 'price' => 149.00],
                'filters' => ['category' => 'hardware', 'in_stock' => true],
            ],
            [
                'id' => 102,
                'text' => 'Comfortable ergonomic wrist rest with memory foam cushion',
                'payload' => ['id' => 102, 'title' => 'Wrist Rest', 'slug' => 'wrist-rest', 'price' => 24.50],
                'filters' => ['category' => 'accessories', 'in_stock' => true],
            ],
        ]);

        $manifest = new \Strata\Engine\Binary\Manifest();
        $manifest->addSegment($segFile, 2, level: 0);
        $manifest->save($this->storageDir . '/manifest.json');
    }

    protected function tearDown(): void
    {
        // Cleanup temp storage
        $files = glob($this->storageDir . '/*');
        if (is_array($files)) {
            foreach ($files as $f) {
                unlink($f);
            }
        }
        if (is_dir($this->storageDir)) {
            rmdir($this->storageDir);
        }
        parent::tearDown();
    }

    public function testFluentSearchReturnsResults(): void
    {
        $results = Strata::index('products')
            ->query('mechanical keyboard')
            ->fast()
            ->get();

        $this->assertNotEmpty($results);
        $this->assertSame('Dev Keyboard', $results[0]['title']);
        $this->assertEquals(149.00, $results[0]['price']);
    }

    public function testFilterExcludesNonMatchingRecords(): void
    {
        $results = Strata::index('products')
            ->query('ergonomic')
            ->filter(['category' => 'hardware'])
            ->fast()
            ->get();

        // 'Wrist Rest' has category 'accessories', so should not match
        $this->assertEmpty($results);
    }
}
