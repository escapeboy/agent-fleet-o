<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Actions\SteerExperimentAction;
use App\Domain\Experiment\Models\Experiment;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class ExperimentSteerTool extends Tool
{
    protected string $name = 'experiment_steer';

    protected string $description = 'Queue a one-shot steering message for a running experiment. The message is prepended to the system prompt of the next LLM call and then cleared. Useful for mid-run corrections like "use staging DB, not prod".';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('The experiment UUID')
                ->required(),
            'message' => $schema->string()
                ->description('The steering instruction to inject (max 2000 chars). Will replace any previously queued message.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'experiment_id' => 'required|string',
            'message' => 'required|string|min:1|max:2000',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $experiment = Experiment::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['experiment_id']);

        if (! $experiment) {
            return Response::error('Experiment not found.');
        }

        try {
            $result = app(SteerExperimentAction::class)->execute(
                experiment: $experiment,
                message: $validated['message'],
                userId: auth()->id(),
            );

            return Response::text(json_encode([
                'success' => true,
                'experiment_id' => $result->id,
                'queued_at' => $result->orchestration_config['steering_queued_at'] ?? null,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
