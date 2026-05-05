<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Services\DoneConditionJudge;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Run the Done-Condition Judge against an experiment ad-hoc, returning the
 * verdict without applying any state transition. Useful for previewing what
 * the gate would say before requesting a transition to Completed.
 *
 * Marked destructive because it incurs an LLM call (cost) and writes a
 * verdict in the gateway's audit trail.
 */
#[IsDestructive]
#[AssistantTool('write')]
class ExperimentDoneJudgeRunTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'experiment_done_judge_run';

    protected string $description = 'Run the Done-Condition Judge on an experiment without transitioning. Returns {confirmed, reasoning, missing[], next_actions[], judge_model}. Useful for previewing whether the Done-Condition Gate would accept the agent\'s "I am done" claim.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('Experiment UUID')
                ->required(),
            'evidence' => $schema->string()
                ->description('Optional evidence text. When omitted, the recent stage outputs are used.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'experiment_id' => 'required|string',
            'evidence' => 'nullable|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $experiment = Experiment::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['experiment_id']);
        if (! $experiment) {
            return $this->notFoundError('experiment');
        }

        $features = $this->resolveFeatures($experiment);
        if ($features === []) {
            return Response::json([
                'experiment_id' => $experiment->id,
                'skipped' => true,
                'reason' => 'no workspace_contract feature-list found on any agent execution for this experiment',
            ]);
        }

        $evidence = $validated['evidence'] ?? null;
        $judge = app(DoneConditionJudge::class);
        $verdict = $judge->evaluate($experiment, $features, $evidence ?? ['note' => 'evidence omitted from MCP call']);

        return Response::json([
            'experiment_id' => $experiment->id,
            'verdict' => $verdict->toArray(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveFeatures(Experiment $experiment): array
    {
        $latest = AgentExecution::query()
            ->where('experiment_id', $experiment->id)
            ->whereNotNull('workspace_contract')
            ->latest('updated_at')
            ->first();
        if (! $latest) {
            return [];
        }
        $jsonStr = $latest->workspace_contract['feature_list_json'] ?? null;
        if (! is_string($jsonStr)) {
            return [];
        }
        $decoded = json_decode($jsonStr, true);

        return is_array($decoded) && is_array($decoded['features'] ?? null) ? $decoded['features'] : [];
    }
}
