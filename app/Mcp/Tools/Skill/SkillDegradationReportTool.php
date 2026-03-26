<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Models\Skill;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Produces a report of active skills whose quality metrics have fallen below
 * the configured degradation thresholds, indicating they may need evolution.
 * Only skills with at least the minimum sample size are evaluated.
 */
#[IsReadOnly]
#[IsIdempotent]
class SkillDegradationReportTool extends Tool
{
    protected string $name = 'skill_degradation_report';

    protected string $description = 'List skills that have degraded below quality thresholds and may need evolution.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'team_id' => $schema->string()
                ->description('Team ID to scope the report. Omit to check across all teams.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $minSample = (int) config('skills.degradation.min_sample_size', 10);

        $query = Skill::query()
            ->where('status', SkillStatus::Active)
            ->where('applied_count', '>=', $minSample);

        if ($teamId = $request->get('team_id')) {
            $query->where('team_id', $teamId);
        }

        $degraded = $query->get()->filter(fn ($skill) => $skill->isDegraded());

        if ($degraded->isEmpty()) {
            return Response::text(json_encode(['count' => 0, 'degraded_skills' => []]));
        }

        $report = $degraded->map(fn ($skill) => [
            'id' => $skill->id,
            'name' => $skill->name,
            'reliability_rate' => round($skill->reliability_rate * 100, 1).'%',
            'quality_rate' => round($skill->quality_rate * 100, 1).'%',
            'health_score' => round($skill->health_score * 100, 1).'%',
            'applied_count' => $skill->applied_count,
        ])->values()->toArray();

        return Response::text(json_encode([
            'count' => count($report),
            'degraded_skills' => $report,
        ]));
    }
}
