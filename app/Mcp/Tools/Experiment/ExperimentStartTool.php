<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ExperimentStartTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'experiment_start';

    protected string $description = 'Start a draft experiment, kicking off the AI pipeline (scoring → planning → building → executing). The experiment must be in draft status.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('The experiment UUID to start')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $experimentId = $request->get('experiment_id');
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $experiment = Experiment::withoutGlobalScopes()->where('team_id', $teamId)->find($experimentId);

        if (! $experiment) {
            return $this->notFoundError('experiment', $experimentId);
        }

        if ($experiment->status !== ExperimentStatus::Draft) {
            return $this->failedPreconditionError("Cannot start experiment in '{$experiment->status->value}' status. Only draft experiments can be started. Use experiment_resume for paused experiments.");
        }

        try {
            $result = app(TransitionExperimentAction::class)->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Scoring,
                reason: 'Started via MCP',
                actorId: auth()->id(),
            );

            return Response::text(json_encode([
                'success' => true,
                'experiment_id' => $result->id,
                'title' => $result->title,
                'status' => $result->status->value,
                'started_at' => $result->started_at?->toIso8601String(),
                'message' => "Experiment '{$result->title}' has been started and is now in the scoring pipeline.",
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
