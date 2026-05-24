<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Models\Team;

/**
 * Deterministic check of generated text against a team's Brand Voice policy.
 *
 * Borrowed from dotCMS's "Brand Voice / Content Standards" governance idea:
 * the policy steers generation (via {@see FormatGuidePromptInjector}) and is
 * independently verified here. This validator is intentionally deterministic
 * (no LLM) so it is free, stable, and safe to run on every send.
 *
 * Policy lives at `team.settings['brand_voice']`:
 *   tone:              free-text steering (prompt-only, not checked here)
 *   forbidden_phrases: list of phrases that must not appear (case-insensitive)
 *   glossary:          [{term, preferred}] — flags `term` used in place of `preferred`
 *   enforce:           off | warn | block (consumed by callers, not here)
 */
class BrandVoiceValidator
{
    public function validate(string $content, ?string $teamId): BrandVoiceResult
    {
        $policy = $this->policyFor($teamId);
        if ($policy === []) {
            return new BrandVoiceResult(passed: true);
        }

        $haystack = mb_strtolower($content);
        $violations = [];

        foreach ($this->forbiddenPhrases($policy) as $phrase) {
            if ($phrase !== '' && str_contains($haystack, mb_strtolower($phrase))) {
                $violations[] = "Forbidden phrase used: \"{$phrase}\".";
            }
        }

        foreach ($this->glossary($policy) as [$term, $preferred]) {
            if ($term !== '' && str_contains($haystack, mb_strtolower($term))) {
                $violations[] = $preferred !== ''
                    ? "Use \"{$preferred}\" instead of \"{$term}\"."
                    : "Discouraged term used: \"{$term}\".";
            }
        }

        return new BrandVoiceResult(passed: $violations === [], violations: $violations);
    }

    /**
     * @return array<string, mixed>
     */
    private function policyFor(?string $teamId): array
    {
        if ($teamId === null) {
            return [];
        }

        $policy = Team::withoutGlobalScopes()->find($teamId)?->settings['brand_voice'] ?? [];

        return is_array($policy) ? $policy : [];
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return list<string>
     */
    private function forbiddenPhrases(array $policy): array
    {
        $phrases = $policy['forbidden_phrases'] ?? [];
        if (! is_array($phrases)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($p): string => is_string($p) ? trim($p) : '', $phrases),
            static fn (string $p): bool => $p !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return list<array{0: string, 1: string}>
     */
    private function glossary(array $policy): array
    {
        $entries = $policy['glossary'] ?? [];
        if (! is_array($entries)) {
            return [];
        }

        $out = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $term = is_string($entry['term'] ?? null) ? trim($entry['term']) : '';
            $preferred = is_string($entry['preferred'] ?? null) ? trim($entry['preferred']) : '';
            if ($term !== '') {
                $out[] = [$term, $preferred];
            }
        }

        return $out;
    }
}
