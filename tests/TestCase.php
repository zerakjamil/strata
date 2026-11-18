<?php

declare(strict_types=1);

namespace Strata\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Strata\StrataServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            StrataServiceProvider::class,
        ];
    }
}
