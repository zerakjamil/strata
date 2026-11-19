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

    public function testScoutPaginationAndTotalCount(): void
    {
        $tempDir = sys_get_temp_dir() . '/strata_page_' . uniqid();
        mkdir($tempDir, 0755, true);

        $strata = new Strata(['path' => $tempDir]);
        $engine = new StrataEngine($strata);

        $models = [];
        for ($i = 1; $i <= 5; $i++) {
            $id = 200 + $i;
            $models[] = new class($id) extends Model {
                public function __construct(public int $mid) {}
                public function searchableAs(): string { return 'test_page_products'; }
                public function getScoutKey(): int { return $this->mid; }
                public function toSearchableArray(): array {
                    return [
                        'id' => $this->mid,
                        'title' => "High Performance Mechanical Gaming Keyboard Model {$this->mid}",
                    ];
                }
            };
        }

        $engine->update(new Collection($models));

        // Create Scout builder mock
        $builder = new \Laravel\Scout\Builder(new class extends Model {
            public function searchableAs(): string { return 'test_page_products'; }
            public function getScoutKeyName(): string { return 'id'; }
        }, 'mechanical gaming keyboard');

        // Test Page 1 (limit 2, page 1)
        $page1Results = $engine->paginate($builder, 2, 1);
        $this->assertIsArray($page1Results);
        $this->assertArrayHasKey('results', $page1Results);
        $this->assertCount(2, $page1Results['results']);
        $this->assertEquals(5, $engine->getTotalCount($page1Results));

        // Test Page 2 (limit 2, page 2)
        $page2Results = $engine->paginate($builder, 2, 2);
        $this->assertCount(2, $page2Results['results']);
        $this->assertEquals(5, $engine->getTotalCount($page2Results));

        // Verify page 1 and page 2 returned distinct items
        $p1Ids = $engine->mapIds($page1Results)->all();
        $p2Ids = $engine->mapIds($page2Results)->all();
        $this->assertEmpty(array_intersect($p1Ids, $p2Ids), 'Paginated pages must not overlap');

        // Test Index Flushing
        $engine->flush($models[0]);
        $afterFlush = $strata->search('test_page_products', 'keyboard');
        $this->assertCount(0, $afterFlush);

        // Clean up
        @rmdir($tempDir);
    }
}
