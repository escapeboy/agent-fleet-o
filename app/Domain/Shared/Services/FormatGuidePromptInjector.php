<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Models\Team;

/**
 * Appends a team's free-text format / brand guide to an LLM system prompt.
 *
 * Borrowed from CraftBot's FORMAT.md — one house-style document the agent
 * consults before generating any user-facing content. The guide is stored
 * as free-text markdown at `team.settings['format_guide']`; injection is a
 * no-op when no team or no guide is set.
 */
class FormatGuidePromptInjector
{
    public function inject(string $systemPrompt, ?string $teamId): string
    {
        if ($teamId === null) {
            return $systemPrompt;
        }

        $guide = $this->guideFor($teamId);
        if ($guide === '') {
            return $systemPrompt;
        }

        return $systemPrompt
            ."\n\n## Team Format & Brand Guide\n\n"
            ."Follow this team's house style for all generated content:\n\n"
            .$guide;
    }

    private function guideFor(string $teamId): string
    {
        $team = Team::find($teamId);
        $guide = $team?->settings['format_guide'] ?? '';

        return is_string($guide) ? trim($guide) : '';
    }
}
