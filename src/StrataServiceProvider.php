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
            return new Strata($app['config']->get('strata') ?? []);
        });

        $this->app->register(Octane\StrataOctaneServiceProvider::class);
    }

    public function boot(): void
    {
        $this->callAfterResolving(\Laravel\Scout\EngineManager::class, function (\Laravel\Scout\EngineManager $manager) {
            $manager->extend('strata', function ($app) {
                return new Scout\StrataEngine($app->make(Strata::class));
            });
        });

        $this->loadRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/strata.php' => $this->app->configPath('strata.php'),
            ], 'strata-config');

            $this->commands([
                Commands\StrataSeedCommand::class,
                Commands\StrataIndexCommand::class,
                Commands\StrataCompactCommand::class,
                Commands\StrataBenchmarkCommand::class,
                Commands\StrataStatusCommand::class,
            ]);
        }
    }

    private function loadRoutes(): void
    {
        $router = $this->app['router'];
        $router->get('api/strata/search', Http\Controllers\StrataSearchController::class)
            ->name('strata.api.search');
    }
}
