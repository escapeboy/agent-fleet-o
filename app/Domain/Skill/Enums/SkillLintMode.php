<?php

namespace App\Domain\Skill\Enums;

/**
 * Skill authoring failure modes catalogued by ZooEval. Used by SkillQualityLinter
 * to flag prompts that are likely to underperform or mislead the model.
 */
enum SkillLintMode: string
{
    case PhantomTooling = 'phantom_tooling';
    case ReferenceBloat = 'reference_bloat';
    case EmptyGuidance = 'empty_guidance';
    case MissingOutputSchema = 'missing_output_schema';

    public function label(): string
    {
        return match ($this) {
            self::PhantomTooling => 'Phantom tooling',
            self::ReferenceBloat => 'Reference bloat',
            self::EmptyGuidance => 'Empty guidance',
            self::MissingOutputSchema => 'Missing output schema',
        };
    }
}
