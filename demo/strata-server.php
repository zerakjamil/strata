<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Strata\Engine\Binary\Manifest;
use Strata\Engine\Binary\SegmentReader;

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($uri === '/api/health') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'healthy', 'engine' => 'strata']);
    exit;
}

if ($uri !== '/api/search') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not Found']);
    exit;
}

$query = (string) ($_GET['q'] ?? '');
$limit = max(1, min(100, (int) ($_GET['limit'] ?? 10)));
$status = $_GET['status'] ?? null;

$filters = [];
if ($status !== null) {
    $filters['status'] = (string) $status;
}

// Storage path inside container or fallback
$storageDir = getenv('STRATA_PATH') ?: (__DIR__ . '/../storage/strata');
$manifestPath = $storageDir . '/manifest.json';

static $readers = null;
if ($readers === null) {
    $readers = [];
    if (file_exists($manifestPath)) {
        $manifest = Manifest::load($manifestPath);
        foreach ($manifest->segments as $segMeta) {
            $path = $segMeta['path'];
            if (file_exists($path)) {
                $readers[] = SegmentReader::open($path);
            }
        }
    }
}

$t0 = hrtime(true);
$results = [];

foreach ($readers as $reader) {
    $segResults = $reader->search(
        query: $query,
        filters: $filters,
        limit: $limit,
        fastPath: true
    );
    foreach ($segResults as $r) {
        $results[] = $r;
    }
    if (count($results) >= $limit * 2) {
        break;
    }
}

usort($results, fn($a, $b) => ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0));
if (count($results) > $limit) {
    $results = array_slice($results, 0, $limit);
}

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
        'engine' => 'Strata (In-Process FastPath)',
        'latency_ms' => $latencyMs,
    ],
], JSON_UNESCAPED_SLASHES);
