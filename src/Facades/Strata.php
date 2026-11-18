<?php

declare(strict_types=1);

namespace Strata\Facades;

use Illuminate\Support\Facades\Facade;
use Strata\Scout\StrataBuilder;

/**
 * @method static StrataBuilder index(string $indexName)
 * @method static array search(string $index, string $query = '', ?string $queryVector = null, array $filters = [], int $limit = 20, bool $fastPath = true)
 *
 * @see \Strata\Strata
 */
final class Strata extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Strata\Strata::class;
    }
}
