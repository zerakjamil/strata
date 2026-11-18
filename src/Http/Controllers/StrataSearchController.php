<?php

declare(strict_types=1);

namespace Strata\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Strata\Facades\Strata;

final class StrataSearchController
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = (string) $request->query('q', '');
        $limit = max(1, min(100, (int) $request->query('limit', 10)));
        $status = $request->query('status');

        $filters = [];
        if ($status !== null) {
            $filters['status'] = (string) $status;
        }

        $results = Strata::index('products')
            ->query($query)
            ->filter($filters)
            ->limit($limit)
            ->fast()
            ->get();

        return response()->json([
            'data' => $results,
            'meta' => [
                'count' => count($results),
                'query' => $query,
            ],
        ]);
    }
}
