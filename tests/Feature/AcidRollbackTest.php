<?php

declare(strict_types=1);

namespace Strata\Tests\Feature;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Strata\Engine\Binary\SegmentWriter;
use Strata\Scout\StrataEngine;
use Strata\Strata;
use Strata\Tests\TestCase;

/**
 * Systems-Level Verification: Transaction ACID Rollback Proof
 *
 * Verifies that aborted database transactions leave binary segments,
 * manifests, and delta tombstone bitmasks (.del) 100% bit-for-bit identical
 * via recursive SHA-256 integrity proofs.
 */
final class AcidRollbackTest extends TestCase
{
    private string $storageDir;
    private Strata $strata;
    private StrataEngine $engine;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageDir = sys_get_temp_dir() . '/strata_acid_' . uniqid();
        mkdir($this->storageDir, 0755, true);

        $this->strata = new Strata(['path' => $this->storageDir]);
        $this->engine = new StrataEngine($this->strata);
    }

    protected function tearDown(): void
    {
        $this->recursiveRmdir($this->storageDir);
        parent::tearDown();
    }

    public function testAbortedTransactionLeavesSegmentAndDelFilesBitForBitIdentical(): void
    {
        $indexName = 'acid_products';

        // 1. Initial State: Index 3 production documents
        $m1 = $this->createMockProduct(1001, 'Mechanical Ergonomic Keyboard', $indexName);
        $m2 = $this->createMockProduct(1002, 'Wireless Vertical Mouse', $indexName);
        $m3 = $this->createMockProduct(1003, 'Noise Cancelling Headphones', $indexName);

        $this->engine->update(new Collection([$m1, $m2, $m3]));

        // Assert initial searchability
        $this->assertCount(1, $this->strata->search($indexName, 'Keyboard'));
        $this->assertCount(1, $this->strata->search($indexName, 'Mouse'));
        $this->assertCount(1, $this->strata->search($indexName, 'Headphones'));

        // Capture cryptographic SHA-256 snapshot of index directory
        $preHashes = $this->computeDirectorySha256($this->storageDir);
        $this->assertNotEmpty($preHashes, 'Storage directory must contain segment and manifest files');

        // 2. Aborted Transaction: Attempt delete inside transaction that rolls back
        DB::beginTransaction();
        try {
            // Delete document 1001
            $this->engine->delete(new Collection([$m1]));

            // Also attempt to index document 1004
            $m4 = $this->createMockProduct(1004, 'Ultra Wide Curved Monitor', $indexName);
            $this->engine->update(new Collection([$m4]));

            // Simulate application exception / rollback trigger
            throw new \RuntimeException('Database constraint violation or transaction abort');
        } catch (\RuntimeException $e) {
            DB::rollBack();
        }

        // 3. Cryptographic Verification: Post-rollback SHA-256 check
        $postRollbackHashes = $this->computeDirectorySha256($this->storageDir);

        $this->assertSame(
            $preHashes,
            $postRollbackHashes,
            'CRITICAL ACID VIOLATION: Filesystem state altered after transaction rollback! SHA-256 hashes must match bit-for-bit.'
        );

        // Document 1001 must still exist and be findable
        $searchResults1001 = $this->strata->search($indexName, 'Keyboard');
        $this->assertCount(1, $searchResults1001, 'Rolled-back delete must NOT tombstone the document');
        $this->assertEquals(1001, $searchResults1001[0]['id']);

        // Document 1004 must NOT exist
        $searchResults1004 = $this->strata->search($indexName, 'Monitor');
        $this->assertCount(0, $searchResults1004, 'Rolled-back insert must NOT appear in search index');

        // 4. Committed Transaction: Now commit a legitimate delete
        DB::beginTransaction();
        $this->engine->delete(new Collection([$m1]));
        DB::commit();

        // Document 1001 must now be tombstoned
        $searchResultsAfterCommit = $this->strata->search($indexName, 'Keyboard');
        $this->assertCount(0, $searchResultsAfterCommit, 'Committed delete must tombstone document from results');

        // Document 1002 and 1003 remain active
        $this->assertCount(1, $this->strata->search($indexName, 'Mouse'));
        $this->assertCount(1, $this->strata->search($indexName, 'Headphones'));

        // Delta tombstone bitmask file (.del) must now be created
        $postCommitHashes = $this->computeDirectorySha256($this->storageDir);
        $this->assertNotSame(
            $preHashes,
            $postCommitHashes,
            'Committed delete must update .del tombstone bitmask on disk'
        );

        $delFiles = array_filter(array_keys($postCommitHashes), fn($p) => str_ends_with($p, '.del'));
        $this->assertNotEmpty($delFiles, 'Tombstone bitmask file (.del) must be present after commit');
    }

    /**
     * @return array<string, string> Map of relative path => sha256 checksum
     */
    private function computeDirectorySha256(string $dir): array
    {
        $hashes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $relativePath = substr($item->getPathname(), strlen($dir) + 1);
                $hashes[$relativePath] = hash_file('sha256', $item->getPathname());
            }
        }

        ksort($hashes);
        return $hashes;
    }

    private function createMockProduct(int $id, string $title, string $indexName): Model
    {
        return new class($id, $title, $indexName) extends Model {
            public $id;
            private string $mockTitle;
            private string $mockIndex;

            public function __construct(int $id, string $title, string $indexName) {
                $this->id = $id;
                $this->mockTitle = $title;
                $this->mockIndex = $indexName;
            }

            public function searchableAs(): string {
                return $this->mockIndex;
            }

            public function getScoutKey(): int {
                return $this->id;
            }

            public function toSearchableArray(): array {
                return [
                    'id' => $this->id,
                    'title' => $this->mockTitle,
                ];
            }
        };
    }

    private function recursiveRmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
