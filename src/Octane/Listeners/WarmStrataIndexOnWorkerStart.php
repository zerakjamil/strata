<?php

declare(strict_types=1);

namespace Strata\Octane\Listeners;

use Strata\Facades\Strata;

final class WarmStrataIndexOnWorkerStart
{
    public function handle(mixed $event): void
    {
        // Warm the active segments in memory on worker start
        try {
            Strata::search('products', query: 'warmup', limit: 1);
        } catch (\Throwable) {
            // Ignore if index is not yet built
        }
    }
}
