<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Models\Team;

/**
 * Appends a team's house style to an LLM system prompt.
 *
 * Two layers, both stored on `team.settings`:
 *  - `format_guide` — free-text markdown house-style document (CraftBot).
 *  - `brand_voice` — structured Brand Voice policy (dotCMS-borrowed):
 *    tone, glossary, and forbidden phrases. Rendered as explicit rules so the
 *    same policy that {@see BrandVoiceValidator} enforces also steers
 *    generation up front.
 *
 * Injection is a no-op when no team is set or neither layer is configured.
 */
class FormatGuidePromptInjector
{
    public function inject(string $systemPrompt, ?string $teamId): string
    {
        if ($teamId === null) {
            return $systemPrompt;
        }

        $team = Team::withoutGlobalScopes()->find($teamId);
        if ($team === null) {
            return $systemPrompt;
        }

        $guide = $this->guideFor($team);
        if ($guide !== '') {
            $systemPrompt .= "\n\n## Team Format & Brand Guide\n\n"
                ."Follow this team's house style for all generated content:\n\n"
                .$guide;
        }

        $brandVoice = $this->brandVoiceBlock($team);
        if ($brandVoice !== '') {
            $systemPrompt .= "\n\n## Brand Voice\n\n".$brandVoice;
        }

        return $systemPrompt;
    }

    private function guideFor(Team $team): string
    {
        $guide = $team->settings['format_guide'] ?? '';

        return is_string($guide) ? trim($guide) : '';
    }

    private function brandVoiceBlock(Team $team): string
    {
        $policy = $team->settings['brand_voice'] ?? [];
        if (! is_array($policy)) {
            return '';
        }

        $lines = [];

        $tone = is_string($policy['tone'] ?? null) ? trim($policy['tone']) : '';
        if ($tone !== '') {
            $lines[] = "Tone: {$tone}";
        }

        $forbidden = array_values(array_filter(
            array_map(
                static fn ($p): string => is_string($p) ? trim($p) : '',
                is_array($policy['forbidden_phrases'] ?? null) ? $policy['forbidden_phrases'] : [],
            ),
            static fn (string $p): bool => $p !== '',
        ));
        if ($forbidden !== []) {
            $lines[] = 'Never use these phrases: '.implode(', ', $forbidden).'.';
        }

        $glossary = [];
        foreach (is_array($policy['glossary'] ?? null) ? $policy['glossary'] : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $term = is_string($entry['term'] ?? null) ? trim($entry['term']) : '';
            $preferred = is_string($entry['preferred'] ?? null) ? trim($entry['preferred']) : '';
            if ($term !== '' && $preferred !== '') {
                $glossary[] = "\"{$term}\" → \"{$preferred}\"";
            }
        }
        if ($glossary !== []) {
            $lines[] = 'Preferred vocabulary: '.implode('; ', $glossary).'.';
        }

        return implode("\n", $lines);
    }
}
