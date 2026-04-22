<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Throwable;

#[IsDestructive]
#[AssistantTool('write')]
class ExperimentSkipStageTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'experiment_skip_stage';

    protected string $description = 'Skip the current stage of an experiment and attempt to advance to the Executing state. Use with caution — not all state transitions are valid.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()->description('The experiment ID.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $experiment = Experiment::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('experiment_id'));
        if (! $experiment) {
            return $this->notFoundError('experiment');
        }

        try {
            $updated = app(TransitionExperimentAction::class)->execute(
                $experiment,
                ExperimentStatus::Executing,
                'Skipped via MCP tool',
            );

            return Response::text(json_encode([
                'success' => true,
                'id' => $updated->id,
                'status' => $updated->status->value,
            ]));
        } catch (Throwable $e) {
            return $this->failedPreconditionError('Cannot skip stage from current state ('.$experiment->status->value.'): '.$e->getMessage());
        }
    }
}
