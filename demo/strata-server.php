<?php

declare(strict_types=1);

namespace Strata\Demo;

require_once __DIR__ . '/../vendor/autoload.php';

use PDO;
use Strata\Strata;

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($uri === '/api/health') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'healthy', 'engine' => 'strata']);
    exit;
}

$storageDir = getenv('STRATA_PATH');
if (!$storageDir || !file_exists($storageDir . '/manifest.json')) {
    if (file_exists('/app/storage/strata/manifest.json')) {
        $storageDir = '/app/storage/strata';
    } elseif (file_exists(__DIR__ . '/../storage/strata/manifest.json')) {
        $storageDir = __DIR__ . '/../storage/strata';
    } else {
        $storageDir = __DIR__ . '/../vendor/orchestra/testbench-core/laravel/storage/strata';
    }
}

static $strata = null;
if ($strata === null) {
    $strata = new Strata(['path' => $storageDir]);
}

$query = (string) ($_GET['q'] ?? '');
$limit = max(1, min(100, (int) ($_GET['limit'] ?? 10)));
$status = $_GET['status'] ?? null;
$index = (string) ($_GET['index'] ?? 'products');

$filters = [];
if ($status !== null) {
    $filters['status'] = (string) $status;
}

// 1. DELETE Endpoint: Test real-time tombstoning live
if ($uri === '/api/delete') {
    $id = $_GET['id'] ?? null;
    if (!$id && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $id = $body['id'] ?? null;
    }

    if (!$id) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Missing document ID parameter (?id=...)']);
        exit;
    }

    $deletedCount = $strata->deleteKeys([$id], $index);

    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'message' => "Document ID {$id} tombstoned via atomic bitmask",
        'deleted_records' => $deletedCount,
        'index' => $index,
    ]);
    exit;
}

// 2. COMPARE Endpoint: Run Strata and PostgreSQL side-by-side in real time
if ($uri === '/api/compare') {
    // A. Execute Strata
    $mem0 = memory_get_usage();
    $t0 = hrtime(true);
    $strataResults = $strata->search(
        index: $index,
        query: $query,
        filters: $filters,
        limit: $limit,
        fastPath: true
    );
    $t1 = hrtime(true);
    $strataMs = round(($t1 - $t0) / 1_000_000.0, 3);
    $strataMem = memory_get_usage() - $mem0;

    // B. Execute PostgreSQL
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '5432';
    $dbname = getenv('DB_DATABASE') ?: 'strata_bench';
    $user = getenv('DB_USERNAME') ?: 'zerakjamil';
    $pass = getenv('DB_PASSWORD') ?: '';

    $pgResults = [];
    $pgMs = 0.0;
    $pgMem = 0;
    $pgError = null;

    try {
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pgMem0 = memory_get_usage();
        $pgT0 = hrtime(true);

        $whereSql = "to_tsvector('english', title || ' ' || description) @@ plainto_tsquery('english', :query)";
        $params = [':query' => $query];

        if ($status !== null) {
            $whereSql .= " AND status = :status";
            $params[':status'] = (string) $status;
        }

        $sql = "SELECT id, title, slug, price, status 
                FROM strata_bench_products 
                WHERE {$whereSql} 
                LIMIT {$limit}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $pgResults = $stmt->fetchAll();

        $pgT1 = hrtime(true);
        $pgMs = round(($pgT1 - $pgT0) / 1_000_000.0, 3);
        $pgMem = memory_get_usage() - $pgMem0;
    } catch (\Throwable $e) {
        $pgError = $e->getMessage();
    }

    $speedup = $pgMs > 0 ? round($pgMs / max(0.001, $strataMs), 1) : 0;

    header('Content-Type: application/json');
    header('X-Strata-Execution-Ms: ' . $strataMs);
    header('X-Postgres-Execution-Ms: ' . $pgMs);
    header('X-Speedup-Factor: ' . $speedup . 'x');

    echo json_encode([
        'query' => $query,
        'limit' => $limit,
        'comparison' => [
            'strata_latency_ms' => $strataMs,
            'postgres_latency_ms' => $pgMs,
            'speedup' => $speedup > 0 ? "{$speedup}x faster" : 'N/A',
            'strata_hits' => count($strataResults),
            'postgres_hits' => count($pgResults),
            'strata_memory_bytes' => max(0, $strataMem),
            'postgres_memory_bytes' => max(0, $pgMem),
            'pruning_algorithm' => 'Block-Max WAND (Exact Provable Bounds)',
        ],
        'strata_data' => $strataResults,
        'postgres_data' => $pgResults,
        'postgres_error' => $pgError,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// 3. SEARCH Endpoint: High-performance Strata in-process search
if ($uri === '/api/search') {
    $t0 = hrtime(true);
    $results = $strata->search(
        index: $index,
        query: $query,
        filters: $filters,
        limit: $limit,
        fastPath: true
    );
    $t1 = hrtime(true);
    $latencyMs = round(($t1 - $t0) / 1_000_000.0, 3);

    header('Content-Type: application/json');
    header('X-Search-Engine: Strata-In-Process-FastPath');
    header("X-Execution-Time-Ms: {$latencyMs}ms");

    echo json_encode([
        'data' => $results,
        'meta' => [
            'count' => count($results),
            'query' => $query,
            'index' => $index,
            'engine' => 'Strata (In-Process Block-Max WAND)',
            'latency_ms' => $latencyMs,
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'Not Found']);
