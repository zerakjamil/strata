<?php

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($uri === '/api/health') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'healthy', 'engine' => 'postgres']);
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

$host = getenv('DB_HOST') ?: 'postgres';
$port = getenv('DB_PORT') ?: '5432';
$dbname = getenv('DB_DATABASE') ?: 'strata_demo';
$user = getenv('DB_USERNAME') ?: 'postgres';
$pass = getenv('DB_PASSWORD') ?: 'postgres';

static $pdo = null;
if ($pdo === null) {
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

$t0 = hrtime(true);

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
$results = $stmt->fetchAll();

$t1 = hrtime(true);
$latencyMs = round(($t1 - $t0) / 1_000_000.0, 3);

header('Content-Type: application/json');
header('X-Search-Engine: PostgreSQL-tsvector-GIN');
header("X-Execution-Time-Ms: {$latencyMs}ms");

echo json_encode([
    'data' => $results,
    'meta' => [
        'count' => count($results),
        'query' => $query,
        'engine' => 'PostgreSQL (tsvector + GIN)',
        'latency_ms' => $latencyMs,
    ],
], JSON_UNESCAPED_SLASHES);
