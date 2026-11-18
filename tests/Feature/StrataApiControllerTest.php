<?php

declare(strict_types=1);

namespace Strata\Tests\Feature;

use Strata\Engine\Binary\SegmentWriter;
use Strata\Tests\TestCase;

final class StrataApiControllerTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDir = sys_get_temp_dir() . '/strata_api_' . bin2hex(random_bytes(4));
        mkdir($this->storageDir, 0755, true);
        config(['strata.path' => $this->storageDir]);

        $writer = new SegmentWriter();
        $segFile = $this->storageDir . '/segment_api.seg';
        $writer->write($segFile, [
            [
                'id' => 501,
                'text' => 'High resolution 4K gaming monitor with HDR',
                'payload' => ['id' => 501, 'title' => '4K Gaming Monitor', 'price' => 499.00],
                'filters' => ['status' => 'active'],
            ],
            [
                'id' => 502,
                'text' => 'Studio condenser microphone with shock mount',
                'payload' => ['id' => 502, 'title' => 'Studio Microphone', 'price' => 129.00],
                'filters' => ['status' => 'inactive'],
            ],
        ]);

        $manifest = new \Strata\Engine\Binary\Manifest();
        $manifest->addSegment($segFile, 2, level: 0);
        $manifest->save($this->storageDir . '/manifest.json');
    }

    protected function tearDown(): void
    {
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

    public function testSearchApiEndpointReturnsJson(): void
    {
        $response = $this->getJson('/api/strata/search?q=monitor');

        $response->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.title', '4K Gaming Monitor');
    }

    public function testSearchApiFilterApplies(): void
    {
        $response = $this->getJson('/api/strata/search?q=microphone&status=active');

        // Microphone status is inactive, so with status=active it should return 0 results
        $response->assertOk()
            ->assertJsonPath('meta.count', 0);
    }
}
