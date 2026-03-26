<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Models\Skill;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Returns quality metrics for a single skill: reliability rate, quality rate,
 * fallback rate, health score, and whether the skill is currently degraded.
 */
#[IsReadOnly]
#[IsIdempotent]
class SkillQualityTool extends Tool
{
    protected string $name = 'skill_quality';

    protected string $description = 'Get quality metrics for a skill: reliability rate, quality rate, fallback rate, and health score.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_id' => $schema->string()
                ->description('The skill UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['skill_id' => 'required|string']);

        $skill = Skill::find($validated['skill_id']);

        if (! $skill) {
            return Response::error('Skill not found.');
        }

        return Response::text(json_encode([
            'id' => $skill->id,
            'name' => $skill->name,
            'applied_count' => $skill->applied_count,
            'completed_count' => $skill->completed_count,
            'effective_count' => $skill->effective_count,
            'fallback_count' => $skill->fallback_count,
            'reliability_rate' => round($skill->reliability_rate * 100, 1).'%',
            'quality_rate' => round($skill->quality_rate * 100, 1).'%',
            'fallback_rate' => round($skill->fallback_rate * 100, 1).'%',
            'health_score' => round($skill->health_score * 100, 1).'%',
            'is_degraded' => $skill->isDegraded(),
        ]));
    }
}
