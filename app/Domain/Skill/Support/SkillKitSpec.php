<?php

namespace App\Domain\Skill\Support;

use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillType;

/**
 * SkillKit / agentskills.io body conventions.
 *
 * The SkillKit ecosystem (used by `npx skills add <repo>/skills`, including
 * iii-hq/iii) expects every SKILL.md body to document `## When to Use` and
 * `## Boundaries`. These helpers detect and back-fill those sections so FleetQ
 * exports validate cleanly and importers can flag what a third-party doc omits.
 */
class SkillKitSpec
{
    /** @var list<string> Markdown sections SkillKit expects in every SKILL.md body. */
    public const RECOMMENDED_SECTIONS = ['When to Use', 'Boundaries'];

    /**
     * Recommended sections (by title) absent from a Markdown body.
     *
     * @return list<string>
     */
    public static function missingSections(string $body): array
    {
        return array_values(array_filter(
            self::RECOMMENDED_SECTIONS,
            static fn (string $section): bool => ! self::hasSection($body, $section),
        ));
    }

    /**
     * Append a generated section for each recommended heading the body lacks.
     * Idempotent: a body that already documents both sections is returned unchanged.
     */
    public static function appendRecommendedSections(
        string $body,
        string $description,
        RiskLevel $risk,
        SkillType $type,
    ): string {
        $body = rtrim($body);

        foreach (self::missingSections($body) as $section) {
            $content = match ($section) {
                'When to Use' => trim($description),
                'Boundaries' => self::boundariesText($risk, $type),
                default => '',
            };

            $body .= "\n\n## {$section}\n\n{$content}";
        }

        return $body;
    }

    private static function hasSection(string $body, string $title): bool
    {
        $pattern = '/^\s{0,3}#{2,6}\s+'.preg_quote($title, '/').'\s*$/im';

        return preg_match($pattern, $body) === 1;
    }

    private static function boundariesText(RiskLevel $risk, SkillType $type): string
    {
        $base = "This is a {$type->value} skill classified {$risk->value} risk.";

        if ($risk->requiresApproval()) {
            return $base.' It requires human approval before its actions take effect; review its'
                .' inputs and outputs and do not use it outside its described purpose.';
        }

        return $base.' Use it only for its described purpose; do not rely on it outside that scope.';
    }
}
