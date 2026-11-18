<?php

declare(strict_types=1);

namespace Strata\Engine\Lexical;

use Strata\Contracts\TokenizerInterface;

final class SimpleTokenizer implements TokenizerInterface
{
    /**
     * Standard common stop words for indexing.
     *
     * @var array<string, bool>
     */
    private const STOP_WORDS = [
        'a' => true, 'about' => true, 'an' => true, 'and' => true, 'are' => true,
        'as' => true, 'at' => true, 'be' => true, 'by' => true, 'for' => true,
        'from' => true, 'how' => true, 'in' => true, 'is' => true, 'it' => true,
        'of' => true, 'on' => true, 'or' => true, 'that' => true, 'the' => true,
        'this' => true, 'to' => true, 'was' => true, 'what' => true, 'when' => true,
        'where' => true, 'who' => true, 'will' => true, 'with' => true,
    ];

    /**
     * @param bool $removeStopWords
     */
    public function __construct(
        private readonly bool $removeStopWords = true
    ) {}

    /**
     * Tokenize text into an array of normalized token strings.
     *
     * @param string $text
     * @return array<string>
     */
    public function tokenize(string $text): array
    {
        $normalized = mb_strtolower($text, 'UTF-8');
        // Replace all non-alphanumeric unicode characters with spaces
        $cleaned = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized);

        if ($cleaned === null || trim($cleaned) === '') {
            return [];
        }

        $parts = explode(' ', trim($cleaned));
        $tokens = [];

        foreach ($parts as $p) {
            $len = strlen($p);
            if ($len < 2) {
                continue;
            }

            if ($this->removeStopWords && isset(self::STOP_WORDS[$p])) {
                continue;
            }

            $tokens[] = $p;
        }

        return $tokens;
    }
}
