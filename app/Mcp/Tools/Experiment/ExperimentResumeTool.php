<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Actions\ResumeExperimentAction;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ExperimentResumeTool extends Tool
{
    protected string $name = 'experiment_resume';

    protected string $description = 'Resume a paused experiment. Restores the experiment to its previous state.';

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
        $experiment = Experiment::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['experiment_id']);

        if (! $experiment) {
            return Response::error('Experiment not found.');
        }

        try {
            $result = app(ResumeExperimentAction::class)->execute($experiment, auth()->id());

            return Response::text(json_encode([
                'success' => true,
                'experiment_id' => $result->id,
                'status' => $result->status->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
