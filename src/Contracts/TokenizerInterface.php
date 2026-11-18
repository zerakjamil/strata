<?php

declare(strict_types=1);

namespace Strata\Contracts;

interface TokenizerInterface
{
    /**
     * Tokenize text into an array of normalized token strings.
     *
     * @param string $text
     * @return array<string>
     */
    public function tokenize(string $text): array;
}
