<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Actions\ResumeFromCheckpointAction;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ExperimentResumeFromCheckpointTool extends Tool
{
    protected string $name = 'experiment_resume_from_checkpoint';

    protected string $description = 'Resume an experiment from its most recent checkpoint without resetting progress. Unlike retry-from-step, this preserves all checkpoint data and re-triggers execution from where the agent left off.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('The experiment UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'experiment_id' => 'required|string',
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

        $result = app(ResumeFromCheckpointAction::class)->execute($experiment);

        return Response::text(json_encode(array_merge($result, [
            'experiment_id' => $experiment->id,
        ])));
    }
}
