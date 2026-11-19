<?php

declare(strict_types=1);

namespace Strata\Demo;

require_once __DIR__ . '/../vendor/autoload.php';

use PDO;
use Strata\Strata;

/**
 * High-throughput Strata Persistent Worker & Router
 */
final class StrataHttpHandler
{
    private Strata $strata;
    private ?PDO $pdo = null;

    public function __construct(string $storageDir)
    {
        $this->strata = new Strata(['path' => $storageDir]);
        // Pre-warm segments into memory (Laravel Octane Warmup pattern)
        try {
            $this->strata->search('products', 'mechanical keyboard', limit: 10, fastPath: true);
        } catch (\Throwable) {
            // Index might be cold or building
        }
    }

    private function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $port = getenv('DB_PORT') ?: '5432';
            $dbname = getenv('DB_DATABASE') ?: 'strata_bench';
            $user = getenv('DB_USERNAME') ?: 'zerakjamil';
            $pass = getenv('DB_PASSWORD') ?: '';

            $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        return $this->pdo;
    }

    /**
     * Handle incoming HTTP request and return [status, headers, body].
     *
     * @param string $method
     * @param string $uri
     * @param array<string, string> $queryParams
     * @param string|null $rawBody
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    public function handle(string $method, string $uri, array $queryParams = [], ?string $rawBody = null): array
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // 1. Health check
        if ($path === '/api/health') {
            return [
                200,
                ['Content-Type' => 'application/json'],
                json_encode(['status' => 'healthy', 'engine' => 'strata', 'worker' => 'persistent-octane-loop'])
            ];
        }

        $query = (string)($queryParams['q'] ?? '');
        $limit = max(1, min(100, (int)($queryParams['limit'] ?? 10)));
        $status = $queryParams['status'] ?? null;
        $index = (string)($queryParams['index'] ?? 'products');

        $filters = [];
        if ($status !== null) {
            $filters['status'] = (string)$status;
        }

        // 2. Delete Endpoint
        if ($path === '/api/delete') {
            $id = $queryParams['id'] ?? null;
            if (!$id && $method === 'POST' && $rawBody) {
                $bodyData = json_decode($rawBody, true);
                $id = $bodyData['id'] ?? null;
            }

            if (!$id) {
                return [
                    400,
                    ['Content-Type' => 'application/json'],
                    json_encode(['error' => 'Missing document ID parameter (?id=...)'])
                ];
            }

            $deletedCount = $this->strata->deleteKeys([$id], $index);
            return [
                200,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'status' => 'success',
                    'message' => "Document ID {$id} tombstoned via atomic bitmask",
                    'deleted_records' => $deletedCount,
                    'index' => $index,
                ])
            ];
        }

        // 3. Compare Endpoint
        if ($path === '/api/compare') {
            // A. Execute Strata
            $mem0 = memory_get_usage();
            $t0 = hrtime(true);
            $strataResults = $this->strata->search(
                index: $index,
                query: $query,
                filters: $filters,
                limit: $limit,
                fastPath: true
            );
            $t1 = hrtime(true);
            $strataMs = round(($t1 - $t0) / 1_000_000.0, 3);
            $strataMem = max(0, memory_get_usage() - $mem0);

            // B. Execute PostgreSQL
            $pgResults = [];
            $pgMs = 0.0;
            $pgMem = 0;
            $pgError = null;

            try {
                $pdo = $this->getPdo();
                $pgMem0 = memory_get_usage();
                $pgT0 = hrtime(true);

                $whereSql = "to_tsvector('english', title || ' ' || description) @@ plainto_tsquery('english', :query)";
                $params = [':query' => $query];

                if ($status !== null) {
                    $whereSql .= " AND status = :status";
                    $params[':status'] = (string)$status;
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
                $pgMem = max(0, memory_get_usage() - $pgMem0);
            } catch (\Throwable $e) {
                $pgError = $e->getMessage();
            }

            $speedup = ($strataMs > 0 && $pgMs > 0) ? round($pgMs / $strataMs, 1) : 0;

            $payload = json_encode([
                'query' => $query,
                'limit' => $limit,
                'comparison' => [
                    'strata_latency_ms' => $strataMs,
                    'postgres_latency_ms' => $pgMs,
                    'speedup' => $speedup > 0 ? "{$speedup}x faster" : 'N/A',
                    'strata_hits' => count($strataResults),
                    'postgres_hits' => count($pgResults),
                    'strata_memory_bytes' => $strataMem,
                    'postgres_memory_bytes' => $pgMem,
                    'pruning_algorithm' => 'Block-Max WAND (Exact Provable Bounds)',
                ],
                'strata_data' => $strataResults,
                'postgres_data' => $pgResults,
                'postgres_error' => $pgError,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

            return [
                200,
                [
                    'Content-Type' => 'application/json',
                    'X-Strata-Execution-Ms' => (string)$strataMs,
                    'X-Postgres-Execution-Ms' => (string)$pgMs,
                    'X-Speedup-Factor' => "{$speedup}x",
                ],
                $payload
            ];
        }

        // 4. Search Endpoint
        if ($path === '/api/search') {
            $t0 = hrtime(true);
            $results = $this->strata->search(
                index: $index,
                query: $query,
                filters: $filters,
                limit: $limit,
                fastPath: true
            );
            $t1 = hrtime(true);
            $latencyMs = round(($t1 - $t0) / 1_000_000.0, 3);

            $payload = json_encode([
                'data' => $results,
                'meta' => [
                    'count' => count($results),
                    'query' => $query,
                    'index' => $index,
                    'engine' => 'Strata (In-Process Persistent Worker)',
                    'latency_ms' => $latencyMs,
                ],
            ], JSON_UNESCAPED_SLASHES);

            return [
                200,
                [
                    'Content-Type' => 'application/json',
                    'X-Search-Engine' => 'Strata-In-Process-FastPath',
                    'X-Execution-Time-Ms' => "{$latencyMs}ms",
                ],
                $payload
            ];
        }

        return [
            404,
            ['Content-Type' => 'application/json'],
            json_encode(['error' => 'Not Found'])
        ];
    }
}

// Determine storage path
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

// Detect execution mode: Persistent TCP Worker vs php -S built-in router
$isCliDirect = (php_sapi_name() === 'cli' && empty($_SERVER['REQUEST_METHOD']));

if ($isCliDirect) {
    // RUN AS HIGH-PERFORMANCE PERSISTENT WORKER
    $port = (int)(getenv('PORT') ?: 80);
    $server = stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $errstr);

    if (!$server) {
        fwrite(STDERR, "Failed to bind to 0.0.0.0:{$port}: {$errstr}\n");
        exit(1);
    }

    echo "⚡ Strata In-Process Persistent Worker listening on port {$port}...\n";
    $handler = new StrataHttpHandler($storageDir);

    while ($conn = @stream_socket_accept($server, -1)) {
        $raw = fread($conn, 8192);
        if (!$raw) {
            fclose($conn);
            continue;
        }

        $headerEnd = strpos($raw, "\r\n\r\n");
        $headersPart = $headerEnd !== false ? substr($raw, 0, $headerEnd) : $raw;
        $bodyPart = $headerEnd !== false ? substr($raw, $headerEnd + 4) : '';

        $lines = explode("\r\n", $headersPart);
        $reqLine = $lines[0] ?? '';
        $parts = explode(' ', $reqLine);
        $method = strtoupper($parts[0] ?? 'GET');
        $fullUri = $parts[1] ?? '/';

        $parsed = parse_url($fullUri);
        $path = $parsed['path'] ?? '/';
        $queryParams = [];
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);
        }

        [$status, $headers, $responseBody] = $handler->handle($method, $fullUri, $queryParams, $bodyPart);

        $statusText = match ($status) {
            200 => 'OK',
            400 => 'Bad Request',
            404 => 'Not Found',
            500 => 'Internal Server Error',
            default => 'Response',
        };

        $out = "HTTP/1.1 {$status} {$statusText}\r\n";
        foreach ($headers as $k => $v) {
            $out .= "{$k}: {$v}\r\n";
        }
        $out .= "Content-Length: " . strlen($responseBody) . "\r\n";
        $out .= "Connection: close\r\n\r\n";
        $out .= $responseBody;

        fwrite($conn, $out);
        fclose($conn);
    }
} else {
    // FALLBACK: php -S router execution
    static $handler = null;
    if ($handler === null) {
        $handler = new StrataHttpHandler($storageDir);
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $rawBody = file_get_contents('php://input');

    [$status, $headers, $responseBody] = $handler->handle($method, $uri, $_GET, $rawBody ?: null);

    http_response_code($status);
    foreach ($headers as $k => $v) {
        header("{$k}: {$v}");
    }
    echo $responseBody;
    exit;
}
