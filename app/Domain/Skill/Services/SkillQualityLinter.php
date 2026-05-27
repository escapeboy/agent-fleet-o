<?php

namespace App\Domain\Skill\Services;

use App\Domain\Skill\DTOs\SkillLintFinding;
use App\Domain\Skill\Enums\SkillLintMode;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Tool\Models\Tool;

/**
 * Authoring-time, read-only static lint for skills, borrowed from ZooEval's catalogued
 * failure modes (phantom tooling 43%, reference bloat 21%, ...). Advisory only — it never
 * blocks creating or updating a skill. Complements the runtime quality counters and the
 * benchmark optimization loop, which only observe a skill after it executes.
 */
class SkillQualityLinter
{
    /**
     * @return array<int, SkillLintFinding>
     */
    public function lint(Skill $skill): array
    {
        $findings = [];

        $phantom = $this->checkPhantomTooling($skill);
        if ($phantom !== null) {
            $findings[] = $phantom;
        }

        if ($skill->type === SkillType::Llm) {
            $prompt = (string) ($skill->system_prompt ?? '');

            if (trim($prompt) === '') {
                $findings[] = new SkillLintFinding(
                    mode: SkillLintMode::EmptyGuidance,
                    severity: 'info',
                    message: 'LLM skill has no system prompt — it adds no guidance over the bare model.',
                );
            } else {
                $bloat = $this->checkReferenceBloat($prompt);
                if ($bloat !== null) {
                    $findings[] = $bloat;
                }
            }

            if (empty($skill->output_schema)) {
                $findings[] = new SkillLintFinding(
                    mode: SkillLintMode::MissingOutputSchema,
                    severity: 'info',
                    message: 'LLM skill has no output schema — output shape is unconstrained.',
                );
            }
        }

        return $findings;
    }

    private function checkPhantomTooling(Skill $skill): ?SkillLintFinding
    {
        $prompt = (string) ($skill->system_prompt ?? '');
        if (trim($prompt) === '') {
            return null;
        }

        $referenced = $this->extractToolReferences($prompt);
        if ($referenced === []) {
            return null;
        }

        $known = $this->knownToolNames($skill->team_id);
        $missing = array_values(array_filter(
            $referenced,
            fn (string $name) => ! in_array(mb_strtolower($name), $known, true),
        ));

        if ($missing === []) {
            return null;
        }

        return new SkillLintFinding(
            mode: SkillLintMode::PhantomTooling,
            severity: 'warning',
            message: 'Prompt references tools that are not registered for this team.',
            detail: 'Unresolved: '.implode(', ', $missing),
        );
    }

    private function checkReferenceBloat(string $prompt): ?SkillLintFinding
    {
        $threshold = (int) config('skills.lint.bloat_token_threshold', 1500);
        $estimatedTokens = (int) ceil(mb_strlen($prompt) / 4);

        if ($estimatedTokens <= $threshold) {
            return null;
        }

        return new SkillLintFinding(
            mode: SkillLintMode::ReferenceBloat,
            severity: 'warning',
            message: "Prompt is large (~{$estimatedTokens} tokens). ZooEval found trimming bloated skills usually beats extending them.",
            detail: "Threshold: {$threshold} tokens.",
        );
    }

    /**
     * Conservative extraction: backtick-wrapped `name(` calls and explicit "the X tool" phrases.
     *
     * @return array<int, string>
     */
    private function extractToolReferences(string $prompt): array
    {
        $names = [];

        if (preg_match_all('/`([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $prompt, $m)) {
            $names = array_merge($names, $m[1]);
        }

        if (preg_match_all('/\b(?:the|use|call|invoke)\s+`?([a-zA-Z_][a-zA-Z0-9_]+)`?\s+tool\b/i', $prompt, $m)) {
            $names = array_merge($names, $m[1]);
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<int, string> lowercased tool names + slugs available to the team
     */
    private function knownToolNames(?string $teamId): array
    {
        $tools = Tool::withoutGlobalScopes()
            ->where(function ($q) use ($teamId) {
                $q->where('team_id', $teamId)->orWhere('is_platform', true);
            })
            ->get(['name', 'slug', 'tool_definitions']);

        $names = [];
        foreach ($tools as $tool) {
            $names[] = mb_strtolower((string) $tool->name);
            if ($tool->slug) {
                $names[] = mb_strtolower((string) $tool->slug);
            }
            foreach ($tool->tool_definitions ?? [] as $def) {
                if (is_array($def) && isset($def['name'])) {
                    $names[] = mb_strtolower((string) $def['name']);
                }
            }
        }

        return array_values(array_unique($names));
    }
}
