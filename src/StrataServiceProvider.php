<?php

declare(strict_types=1);

namespace Strata;

use Illuminate\Support\ServiceProvider;

final class StrataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/strata.php', 'strata');

        $this->app->singleton(Strata::class, function ($app) {
            return new Strata($app['config']->get('strata'));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/strata.php' => $this->app->configPath('strata.php'),
            ], 'strata-config');

            $this->commands([
                Commands\StrataSeedCommand::class,
                Commands\StrataIndexCommand::class,
                Commands\StrataBenchmarkCommand::class,
                Commands\StrataStatusCommand::class,
            ]);
        }
    }
}
