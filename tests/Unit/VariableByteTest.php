<?php

declare(strict_types=1);

namespace Strata\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Strata\Engine\Lexical\VariableByte;

final class VariableByteTest extends TestCase
{
    public function testEncodeAndDecodeIntegers(): void
    {
        $numbers = [1, 127, 128, 300, 16384, 1000000];
        $encoded = VariableByte::encode($numbers);

        $offset = 0;
        $decoded = VariableByte::decode($encoded, $offset, count($numbers));

        $this->assertSame($numbers, $decoded);
        $this->assertSame(strlen($encoded), $offset);
    }

    public function testEncodeAndDecodeDeltas(): void
    {
        $docIds = [5, 12, 19, 150, 1000, 25000];
        $encoded = VariableByte::encodeDeltas($docIds);

        $offset = 0;
        $decoded = VariableByte::decodeDeltas($encoded, $offset, count($docIds));

        $this->assertSame($docIds, $decoded);
        $this->assertSame(strlen($encoded), $offset);
    }
}
