<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Actions\PauseExperimentAction;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ExperimentPauseTool extends Tool
{
    protected string $name = 'experiment_pause';

    protected string $description = 'Pause a running experiment. The experiment can be resumed later.';

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

        $experiment = Experiment::find($validated['experiment_id']);

        if (! $experiment) {
            return Response::error('Experiment not found.');
        }

        try {
            $result = app(PauseExperimentAction::class)->execute($experiment, auth()->id());

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
