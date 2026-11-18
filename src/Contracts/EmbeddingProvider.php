<?php

declare(strict_types=1);

namespace Strata\Contracts;

interface EmbeddingProvider
{
    /**
     * Generate a dense float vector embedding for the given input text.
     *
     * @param string $text
     * @return array<float>
     */
    public function embed(string $text): array;

    /**
     * The fixed dimensional length of embeddings returned by this provider.
     */
    public function dimensions(): int;
}
