<?php

namespace App\Infrastructure\AI\LoopDetection;

/**
 * Pure, deterministic text-similarity primitives for loop detection.
 *
 * Word-level Bi-Gram Jaccard: catches polymorphic/mutating prompts that reorder
 * or lightly edit wording while keeping substantially the same content.
 */
class SimilarityScorer
{
    /**
     * Tokenize normalized text into the set of adjacent word bigrams (bounded).
     *
     * @return list<string>
     */
    public static function bigrams(string $text, int $maxTokens = 400): array
    {
        // Preserve Unicode letters/digits (Cyrillic included); drop control chars.
        $normalized = mb_strtolower($text);
        $normalized = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $normalized) ?? '';

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($tokens) > $maxTokens) {
            $tokens = array_slice($tokens, 0, $maxTokens);
        }

        $count = count($tokens);

        if ($count === 0) {
            return [];
        }

        if ($count === 1) {
            return [$tokens[0]];
        }

        $bigrams = [];

        for ($i = 0; $i < $count - 1; $i++) {
            $bigrams[$tokens[$i].' '.$tokens[$i + 1]] = true;
        }

        return array_keys($bigrams);
    }

    /**
     * Jaccard similarity between two bigram sets (0.0 .. 1.0).
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function jaccard(array $a, array $b): float
    {
        if ($a === [] && $b === []) {
            return 1.0;
        }

        if ($a === [] || $b === []) {
            return 0.0;
        }

        $setA = array_fill_keys($a, true);
        $setB = array_fill_keys($b, true);

        $intersection = 0;

        foreach ($setA as $key => $_) {
            if (isset($setB[$key])) {
                $intersection++;
            }
        }

        $union = count($setA) + count($setB) - $intersection;

        return $union === 0 ? 0.0 : $intersection / $union;
    }
}
