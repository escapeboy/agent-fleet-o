<?php

namespace App\Infrastructure\AI\Services;

use App\Infrastructure\AI\DTOs\UsageBreakdown;
use Prism\Prism\ValueObjects\Usage;

/**
 * Turns Prism's per-provider Usage into non-overlapping billable buckets.
 *
 * Prism does not normalise these fields across providers (verified against
 * prism-php/prism handlers):
 * - Anthropic: promptTokens = input_tokens, which already EXCLUDES cache reads
 *   and cache writes; both come as separate fields.
 * - OpenAI: promptTokens = input_tokens - cached_tokens; completionTokens
 *   already include reasoning tokens (thoughtTokens is a subset).
 * - Gemini: promptTokens INCLUDES implicitly cached tokens; completionTokens
 *   (candidatesTokenCount) EXCLUDES thinking tokens, which Google bills as output.
 *   Exception: with providerOptions.cachedContentName Prism subtracts cached
 *   tokens itself; FleetQ does not use explicit Gemini caches today.
 * - The stream handlers map usage differently (OpenAI stream does not subtract
 *   cached tokens); only the text/structured paths call this normalizer.
 */
final class UsageNormalizer
{
    public static function fromPrism(string $provider, Usage $usage): UsageBreakdown
    {
        $prompt = max(0, (int) $usage->promptTokens);
        $completion = max(0, (int) $usage->completionTokens);
        $cacheRead = max(0, (int) ($usage->cacheReadInputTokens ?? 0));
        $cacheWrite = max(0, (int) ($usage->cacheWriteInputTokens ?? 0));
        $thoughts = max(0, (int) ($usage->thoughtTokens ?? 0));

        return match ($provider) {
            'anthropic' => new UsageBreakdown($prompt, $cacheRead, $cacheWrite, $completion),
            'openai' => new UsageBreakdown($prompt, $cacheRead, 0, $completion),
            'google', 'gemini' => new UsageBreakdown(max(0, $prompt - $cacheRead), min($cacheRead, $prompt), 0, $completion + $thoughts),
            default => new UsageBreakdown(max(0, $prompt - $cacheRead), min($cacheRead, $prompt), $cacheWrite, $completion),
        };
    }
}
