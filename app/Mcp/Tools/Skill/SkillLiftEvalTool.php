<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Actions\RunSkillLiftEvaluationAction;
use App\Domain\Skill\Exceptions\SkillLiftEvaluationDisabledException;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillLiftEvaluation;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Blind A/B skill-lift evaluation (ZooEval borrow): runs a skill WITH vs WITHOUT
 * itself over an evaluation dataset and reports the judged lift + recommendation.
 * The `run` action spends credits, hence destructive.
 */
#[IsDestructive]
class SkillLiftEvalTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'skill_lift_eval';

    protected string $description = 'Blind A/B "skill lift" evaluation: run a skill WITH vs WITHOUT it over an evaluation dataset, judged by the LLM judge. Actions: run, get, list. Requires team.settings.skill_lift_eval_enabled.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: run, get, list')
                ->enum(['run', 'get', 'list'])
                ->required(),
            'skill_id' => $schema->string()
                ->description('Skill UUID (required for run; filters list)'),
            'evaluation_id' => $schema->string()
                ->description('SkillLiftEvaluation UUID (for get)'),
            'dataset_id' => $schema->string()
                ->description('Evaluation dataset UUID (for run; defaults to the skill\'s linked dataset)'),
            'criteria' => $schema->array()
                ->items($schema->string())
                ->description('Judge criteria, e.g. ["correctness","relevance"]. Defaults to config.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $action = $request->get('action');
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        return match ($action) {
            'run' => $this->run($request, $teamId),
            'get' => $this->get($request->get('evaluation_id'), $teamId),
            'list' => $this->list($request->get('skill_id'), $teamId),
            default => $this->invalidArgumentError("Unknown action: {$action}"),
        };
    }

    private function run(Request $request, string $teamId): Response
    {
        $skillId = $request->get('skill_id');
        if (! $skillId) {
            return $this->invalidArgumentError('skill_id is required for run.');
        }

        $skill = Skill::withoutGlobalScopes()->where('team_id', $teamId)->find($skillId);
        if (! $skill) {
            return $this->notFoundError('skill');
        }

        try {
            $evaluation = app(RunSkillLiftEvaluationAction::class)->execute(
                skill: $skill,
                teamId: $teamId,
                userId: auth()->id() ?? $skill->created_by ?? '',
                datasetId: $request->get('dataset_id'),
                criteria: $request->get('criteria'),
            );
        } catch (SkillLiftEvaluationDisabledException $e) {
            return $this->permissionDeniedError($e->getMessage());
        }

        return Response::text(json_encode($this->present($evaluation)));
    }

    private function get(?string $id, string $teamId): Response
    {
        if (! $id) {
            return $this->invalidArgumentError('evaluation_id is required for get.');
        }

        $evaluation = SkillLiftEvaluation::withoutGlobalScopes()
            ->where('team_id', $teamId)->find($id);
        if (! $evaluation) {
            return $this->notFoundError('skill_lift_evaluation');
        }

        return Response::text(json_encode($this->present($evaluation, withCases: true)));
    }

    private function list(?string $skillId, string $teamId): Response
    {
        $query = SkillLiftEvaluation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderByDesc('created_at')
            ->limit(20);

        if ($skillId) {
            $query->where('skill_id', $skillId);
        }

        return Response::text(json_encode([
            'evaluations' => $query->get()->map(fn ($e) => $this->present($e))->toArray(),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SkillLiftEvaluation $e, bool $withCases = false): array
    {
        $data = [
            'id' => $e->id,
            'skill_id' => $e->skill_id,
            'status' => $e->status->value,
            'with_skill_score' => $e->with_skill_score,
            'without_skill_score' => $e->without_skill_score,
            'delta' => $e->delta,
            'improvement_rate' => $e->improvement_rate,
            'recommendation' => $e->recommendation?->value,
            'cost_credits' => $e->cost_credits,
            'error' => $e->error,
        ];

        if ($withCases) {
            $data['criteria'] = $e->criteria;
            $data['case_results'] = $e->case_results;
            $data['judge_model'] = $e->judge_model;
        }

        return $data;
    }
}
