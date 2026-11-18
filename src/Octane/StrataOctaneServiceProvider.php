<?php

declare(strict_types=1);

namespace Strata\Octane;

use Illuminate\Support\ServiceProvider;
use Strata\Octane\Listeners\WarmStrataIndexOnWorkerStart;

final class StrataOctaneServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Register listener if Octane is present
        if (class_exists(\Laravel\Octane\Events\WorkerStarting::class)) {
            $this->app['events']->listen(
                \Laravel\Octane\Events\WorkerStarting::class,
                WarmStrataIndexOnWorkerStart::class
            );
        }
    }
}
