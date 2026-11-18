<?php

declare(strict_types=1);

namespace Strata\Tests\Unit;

use Laravel\Scout\EngineManager;
use Strata\Scout\StrataEngine;
use Strata\Strata;
use Strata\Tests\TestCase;

final class ScoutDriverTest extends TestCase
{
    public function testScoutDriverResolvesProperly(): void
    {
        $manager = $this->app->make(EngineManager::class);
        $engine = $manager->engine('strata');

        $this->assertInstanceOf(StrataEngine::class, $engine);
    }
}
