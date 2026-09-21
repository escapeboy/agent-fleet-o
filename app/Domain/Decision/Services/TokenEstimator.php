<?php

namespace App\Domain\Decision\Services;

/**
 * Cheap offline token estimate. Jev bills and limits by input tokens, but the
 * only way to learn the exact count is to send the request — which is exactly
 * what the 32k pre-flight check exists to avoid. Four characters per token is
 * the usual English approximation; JSON punctuation makes it conservative.
 */
final class TokenEstimator
{
    public const CHARS_PER_TOKEN = 4;

    public static function estimate(mixed $value): int
    {
        $text = is_string($value)
            ? $value
            : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * The budget Jev enforces is state plus the single longest question, not
     * state plus all questions — see https://docs.typesafe.ai/models.
     *
     * @param  array<string, mixed>|list<mixed>|string  $state
     * @param  array<string, array<string, mixed>>  $questions
     */
    public static function estimateStateWithLongestQuestion(array|string $state, array $questions): int
    {
        $longest = 0;

        foreach ($questions as $question) {
            $longest = max($longest, self::estimate($question));
        }

        return self::estimate($state) + $longest;
    }
}
