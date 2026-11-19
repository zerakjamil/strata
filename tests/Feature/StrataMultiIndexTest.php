<?php

declare(strict_types=1);

namespace Strata\Tests\Feature;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Strata\Scout\StrataEngine;
use Strata\Strata;
use Strata\Tests\TestCase;

final class StrataMultiIndexTest extends TestCase
{
    public function testMultiIndexIsolation(): void
    {
        $tempDir = sys_get_temp_dir() . '/strata_multi_' . uniqid();
        mkdir($tempDir, 0755, true);

        $strata = new Strata(['path' => $tempDir]);
        $engine = new StrataEngine($strata);

        // Model in 'products' index
        $product = new class extends Model {
            public $id = 501;
            public function searchableAs(): string { return 'products'; }
            public function getScoutKey(): int { return 501; }
            public function toSearchableArray(): array {
                return ['id' => 501, 'title' => 'Laravel Architectural Masterclass'];
            }
        };

        // Model in 'articles' index
        $article = new class extends Model {
            public $id = 901;
            public function searchableAs(): string { return 'articles'; }
            public function getScoutKey(): int { return 901; }
            public function toSearchableArray(): array {
                return ['id' => 901, 'title' => 'Laravel Octane High Concurrency Guide'];
            }
        };

        $engine->update(new Collection([$product]));
        $engine->update(new Collection([$article]));

        // Search 'products' for "Laravel" -> should only return the product
        $productResults = $strata->search('products', 'Laravel');
        $this->assertCount(1, $productResults);
        $this->assertEquals(501, $productResults[0]['id']);

        // Search 'articles' for "Laravel" -> should only return the article
        $articleResults = $strata->search('articles', 'Laravel');
        $this->assertCount(1, $articleResults);
        $this->assertEquals(901, $articleResults[0]['id']);

        // Verify storage directories are isolated
        $this->assertDirectoryExists($tempDir . '/products');
        $this->assertDirectoryExists($tempDir . '/articles');

        // Cleanup
        system("rm -rf " . escapeshellarg($tempDir));
    }
}
