<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ExperimentStartTool extends Tool
{
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
            return Response::error('No current team.');
        }
        $experiment = Experiment::withoutGlobalScopes()->where('team_id', $teamId)->find($experimentId);

        if (! $experiment) {
            return Response::error("Experiment {$experimentId} not found. Use experiment_list to discover valid experiment IDs.");
        }

        if ($experiment->status !== ExperimentStatus::Draft) {
            return Response::error("Cannot start experiment in '{$experiment->status->value}' status. Only draft experiments can be started. Use experiment_resume for paused experiments.");
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
            return Response::error($e->getMessage());
        }
    }
}
