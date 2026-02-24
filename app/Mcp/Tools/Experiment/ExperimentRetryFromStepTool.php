<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Actions\RetryFromStepAction;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ExperimentRetryFromStepTool extends Tool
{
    protected string $name = 'experiment_retry_from_step';

    protected string $description = 'Retry a workflow experiment from a specific step. Resets the target step and all downstream steps, then re-dispatches execution from that point.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('The experiment UUID')
                ->required(),
            'step_id' => $schema->string()
                ->description('The playbook step UUID to retry from')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'experiment_id' => 'required|string',
            'step_id' => 'required|string',
        ]);

        $experiment = Experiment::find($validated['experiment_id']);

        if (! $experiment) {
            return Response::error('Experiment not found.');
        }

        $step = PlaybookStep::where('experiment_id', $experiment->id)
            ->where('id', $validated['step_id'])
            ->first();

        if (! $step) {
            return Response::error('Step not found in this experiment.');
        }

        try {
            app(RetryFromStepAction::class)->execute($experiment, $step);

            return Response::text(json_encode([
                'success' => true,
                'message' => 'Retry from step initiated.',
                'experiment_id' => $experiment->id,
                'step_id' => $step->id,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
