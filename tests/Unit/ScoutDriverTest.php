<?php

declare(strict_types=1);

namespace Strata\Tests\Unit;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\EngineManager;
use Strata\Scout\StrataEngine;
use Strata\Strata;
use Strata\Tests\TestCase;

final class ScoutDriverTest extends TestCase
{
    public function testScoutDriverResolvesProperly(): void
    {
        $manager = $this->app->make(EngineManager::class);
        $engine = $manager->engine('strata');

        $this->assertInstanceOf(StrataEngine::class, $engine);
    }

    public function testScoutUpdateAndTombstoneDeleteLifecycle(): void
    {
        $tempDir = sys_get_temp_dir() . '/strata_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        $strata = new Strata(['path' => $tempDir]);
        $engine = new StrataEngine($strata);

        // 1. Create Mock Scout Model instances
        $mockModel1 = new class extends Model {
            public $id = 101;
            public function searchableAs(): string { return 'test_products'; }
            public function getScoutKey(): int { return 101; }
            public function toSearchableArray(): array {
                return ['id' => 101, 'title' => 'Ultra Mechanical Keyboard'];
            }
        };

        $mockModel2 = new class extends Model {
            public $id = 102;
            public function searchableAs(): string { return 'test_products'; }
            public function getScoutKey(): int { return 102; }
            public function toSearchableArray(): array {
                return ['id' => 102, 'title' => 'Ergonomic Vertical Mouse'];
            }
        };

        // 2. Index both models
        $engine->update(new Collection([$mockModel1, $mockModel2]));

        // Verify both are findable
        $results = $strata->search('test_products', 'keyboard');
        $this->assertCount(1, $results);
        $this->assertEquals(101, $results[0]['id']);

        $results = $strata->search('test_products', 'mouse');
        $this->assertCount(1, $results);
        $this->assertEquals(102, $results[0]['id']);

        // 3. Delete model 101 via Scout delete()
        $engine->delete(new Collection([$mockModel1]));

        // Verify model 101 is tombstoned and excluded from search
        $resultsAfterDelete = $strata->search('test_products', 'keyboard');
        $this->assertCount(0, $resultsAfterDelete, 'Deleted document 101 must not appear in search results');

        // Verify model 102 remains unaffected
        $resultsStillActive = $strata->search('test_products', 'mouse');
        $this->assertCount(1, $resultsStillActive);
        $this->assertEquals(102, $resultsStillActive[0]['id']);

        // Verify delta tombstone file was written (.del)
        $manifest = $strata->getManifest('test_products');
        $segPath = $manifest->segments[0]['path'];
        $delPath = substr($segPath, 0, -4) . '.del';
        $this->assertFileExists($delPath, 'Delta tombstone bitmask file (.del) must be created upon delete');

        // Clean up
        @unlink($segPath);
        @unlink($delPath);
        @unlink(dirname($segPath) . '/manifest.json');
        @rmdir(dirname($segPath));
        @rmdir($tempDir);
    }
}
