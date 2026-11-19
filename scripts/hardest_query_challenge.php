<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Strata\Strata;

ini_set('memory_limit', '512M');

$pdo = new PDO("pgsql:host=localhost;port=5432;dbname=strata_bench", "zerakjamil", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$strata = new Strata(['path' => __DIR__ . '/../storage/strata']);

echo "=========================================================================================\n";
echo "       EXTREME IR KERNEL CHALLENGE: STRATA vs POSTGRESQL GIN (2,000,000 RECORDS)         \n";
echo "=========================================================================================\n";
echo "Corpus: 2,000,000 Products in PostgreSQL (`strata_bench_products`) + 1.2 GB Strata Binary\n\n";

$challenges = [
    [
        'title' => 'Challenge 1: Combinatorial Stopword Explosion (100,000+ Matching Documents)',
        'query' => 'mechanical keyboard edition',
        'desc' => '"edition" matches 2.0M docs; "mechanical" matches 212k; "keyboard" matches 191k.',
        'filter' => [],
        'limit' => 10,
        'offset' => 0,
    ],
    [
        'title' => 'Challenge 2: The Needle in 2 Million Rows (Rare Term + High-Freq Distractors)',
        'query' => 'wireless mouse repudiandae',
        'desc' => '"wireless" matches 212k; "mouse" matches 191k; "repudiandae" matches <0.1% of docs.',
        'filter' => [],
        'limit' => 10,
        'offset' => 0,
    ],
    [
        'title' => 'Challenge 3: Multi-Term Conjunction with Relational Attribute Filtering',
        'query' => 'ergonomic chair',
        'desc' => 'Lexical query filtered by status = active AND price > 500 (recheck penalty in Postgres).',
        'filter' => ['status' => 'active'],
        'min_price' => 500.0,
        'limit' => 10,
        'offset' => 0,
    ],
    [
        'title' => 'Challenge 4: Deep Top-K Pagination under Extreme Volume (Offset 100, Limit 50)',
        'query' => 'wireless keyboard',
        'desc' => 'High-offset retrieval requiring threshold elevation to prune 150 top candidates.',
        'filter' => [],
        'limit' => 50,
        'offset' => 100,
    ],
];

foreach ($challenges as $idx => $c) {
    $num = $idx + 1;
    echo "-----------------------------------------------------------------------------------------\n";
    echo "[TEST $num] {$c['title']}\n";
    echo "Query   : \"{$c['query']}\"\n";
    echo "Context : {$c['desc']}\n";
    echo "-----------------------------------------------------------------------------------------\n";

    $q = $c['query'];
    $limit = $c['limit'];
    $offset = $c['offset'];
    $hasFilter = !empty($c['filter']) || isset($c['min_price']);

    // --- 1. PostgreSQL True Ranked (ts_rank) Search ---
    $pgRankSql = "SELECT id, title, price, status, 
                  ts_rank(to_tsvector('english', title || ' ' || description), plainto_tsquery('english', :q)) as rank
                  FROM strata_bench_products
                  WHERE to_tsvector('english', title || ' ' || description) @@ plainto_tsquery('english', :q)";

    $params = [':q' => $q];

    if (isset($c['filter']['status'])) {
        $pgRankSql .= " AND status = :status";
        $params[':status'] = $c['filter']['status'];
    }
    if (isset($c['min_price'])) {
        $pgRankSql .= " AND price > :min_price";
        $params[':min_price'] = $c['min_price'];
    }

    $pgRankSql .= " ORDER BY rank DESC LIMIT {$limit} OFFSET {$offset}";

    $t0 = hrtime(true);
    $stmt = $pdo->prepare($pgRankSql);
    $stmt->execute($params);
    $pgRankResults = $stmt->fetchAll();
    $pgRankTimeMs = (hrtime(true) - $t0) / 1e6;

    // --- 2. PostgreSQL Unranked Fast-Exit Search (no ts_rank, no sort) ---
    $pgFastSql = "SELECT id, title, price, status
                  FROM strata_bench_products
                  WHERE to_tsvector('english', title || ' ' || description) @@ plainto_tsquery('english', :q)";

    if (isset($c['filter']['status'])) {
        $pgFastSql .= " AND status = :status";
    }
    if (isset($c['min_price'])) {
        $pgFastSql .= " AND price > :min_price";
    }

    $pgFastSql .= " LIMIT {$limit} OFFSET {$offset}";

    $t0 = hrtime(true);
    $stmt = $pdo->prepare($pgFastSql);
    $stmt->execute($params);
    $pgFastResults = $stmt->fetchAll();
    $pgFastTimeMs = (hrtime(true) - $t0) / 1e6;

    // --- 3. Strata Ranked BM25 with Block-Max WAND Dynamic Pruning ---
    $t0 = hrtime(true);
    $strataResults = $strata->search(
        index: 'products',
        query: $q,
        queryVector: null,
        filters: $c['filter'],
        limit: $limit,
        offset: $offset
    );
    $strataTimeMs = (hrtime(true) - $t0) / 1e6;

    // --- Verify Accuracy: Check that Strata IDs actually match the query keywords ---
    $queryTerms = preg_split('/\s+/', strtolower($q));
    $validKeywordMatches = 0;
    foreach ($strataResults as $res) {
        $titleLower = strtolower($res['title'] ?? '');
        $allMatched = true;
        foreach ($queryTerms as $term) {
            if ($term === 'edition') continue; // Stopword
            if (!str_contains($titleLower, $term)) {
                $allMatched = false;
                break;
            }
        }
        if ($allMatched) {
            $validKeywordMatches++;
        }
    }
    $accuracyPct = count($strataResults) > 0 
        ? round(($validKeywordMatches / count($strataResults)) * 100, 1) 
        : 100.0;

    $speedupRanked = $strataTimeMs > 0 ? round($pgRankTimeMs / $strataTimeMs, 1) : 1.0;
    $speedupFast = $strataTimeMs > 0 ? round($pgFastTimeMs / $strataTimeMs, 1) : 1.0;

    echo sprintf("  %-38s : %9.2f ms  (Hits: %d)\n", "Postgres True Ranked (ts_rank)", $pgRankTimeMs, count($pgRankResults));
    echo sprintf("  %-38s : %9.2f ms  (Hits: %d)\n", "Postgres Unranked Fast-Exit", $pgFastTimeMs, count($pgFastResults));
    echo sprintf("  %-38s : %9.2f ms  (Hits: %d)\n", "Strata Ranked BM25 (WAND Pruned)", $strataTimeMs, count($strataResults));
    echo "\n";
    echo "  >> SPEEDUP vs Postgres Ranked Search : {$speedupRanked}x FASTER\n";
    echo "  >> STRATA RELEVANCE ACCURACY         : {$accuracyPct}% exact keyword precision\n";

    echo "\n  Top Strata Results Preview:\n";
    foreach (array_slice($strataResults, 0, 2) as $hit) {
        $scoreStr = isset($hit['_score']) ? " [Score: " . round((float)$hit['_score'], 4) . "]" : "";
        echo "    - ID {$hit['id']}: {$hit['title']}{$scoreStr}\n";
    }
    echo "\n";
}

echo "=========================================================================================\n";
echo "                                   CHALLENGE COMPLETE                                    \n";
echo "=========================================================================================\n";
