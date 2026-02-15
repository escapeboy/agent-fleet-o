<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\States\ExperimentTransitionMap;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ExperimentValidTransitionsTool extends Tool
{
    protected string $name = 'experiment_valid_transitions';

    protected string $description = 'Get valid state transitions for an experiment based on its current status.';

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
        $validated = $request->validate(['experiment_id' => 'required|string']);

        $experiment = Experiment::find($validated['experiment_id']);

        if (! $experiment) {
            return Response::error('Experiment not found.');
        }

        $transitions = ExperimentTransitionMap::allowedTransitions($experiment->status);

        return Response::text(json_encode([
            'experiment_id' => $experiment->id,
            'current_status' => $experiment->status->value,
            'valid_transitions' => array_map(fn ($t) => $t->value, $transitions),
        ]));
    }
}
