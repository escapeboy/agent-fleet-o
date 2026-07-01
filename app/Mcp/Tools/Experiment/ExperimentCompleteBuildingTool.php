<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Actions\CompleteBuildingAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
// @mcp-cross-tenant transitive-via-experiment
class ExperimentCompleteBuildingTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'experiment_complete_building';

    protected string $description = 'Signal that building work is complete (PRs opened, fixes applied). Transitions the experiment from building to awaiting_approval and records the outcome. Call this when you have finished your work on a debug-track experiment.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('The experiment UUID')
                ->required(),
            'pr_urls' => $schema->array()
                ->description('URLs of pull requests opened during this fix')
                ->items($schema->string()),
            'summary' => $schema->string()
                ->description('Brief summary of what was done and what was fixed'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'experiment_id' => 'required|string',
            'pr_urls' => 'nullable|array',
            'pr_urls.*' => 'string',
            'summary' => 'nullable|string',
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

        if ($experiment->status !== ExperimentStatus::Building) {
            return $this->validationError("Experiment is in '{$experiment->status->value}' state, not 'building'. Cannot complete.");
        }

        app(CompleteBuildingAction::class)->execute(
            experiment: $experiment,
            prUrls: $validated['pr_urls'] ?? [],
            summary: $validated['summary'] ?? null,
            completedBy: 'agent_mcp',
        );

        return Response::text(json_encode([
            'success' => true,
            'experiment_id' => $experiment->id,
            'status' => ExperimentStatus::AwaitingApproval->value,
            'pr_urls' => $validated['pr_urls'] ?? [],
        ]));
    }
}
