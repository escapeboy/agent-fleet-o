<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\Enums\AnnotationRating;
use App\Domain\Skill\Models\SkillAnnotation;
use App\Domain\Skill\Models\SkillVersion;

/**
 * Persists a human feedback annotation on a skill playground run output.
 */
class AnnotateSkillResponseAction
{
    /**
     * Create an annotation record for a single model output from a playground run.
     *
     * @param  string  $teamId  The team that owns the skill
     * @param  string  $userId  The user providing feedback
     * @param  string  $skillVersionId  The skill version that produced the output
     * @param  string  $modelId  The model that produced the output (e.g. "anthropic/claude-sonnet-4-5")
     * @param  string  $input  The test input that was sent to the model
     * @param  string  $output  The model's output text
     * @param  AnnotationRating  $rating  Whether the output was good or bad
     * @param  string|null  $note  Optional free-text explanation
     */
    public function execute(
        string $teamId,
        string $userId,
        string $skillVersionId,
        string $modelId,
        string $input,
        string $output,
        AnnotationRating $rating,
        ?string $note = null,
    ): SkillAnnotation {
        // Ensure the skill version belongs to the correct team (guard against cross-team writes)
        $version = SkillVersion::withoutGlobalScopes()
            ->whereHas('skill', fn ($q) => $q->where('team_id', $teamId))
            ->findOrFail($skillVersionId);

        return SkillAnnotation::create([
            'skill_version_id' => $version->id,
            'team_id' => $teamId,
            'user_id' => $userId,
            'model_id' => $modelId,
            'input' => $input,
            'output' => $output,
            'rating' => $rating,
            'note' => $note,
        ]);
    }
}
