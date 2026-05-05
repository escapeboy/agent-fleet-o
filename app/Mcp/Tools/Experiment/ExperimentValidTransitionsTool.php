<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\States\ExperimentTransitionMap;
use App\Mcp\Concerns\HasStructuredErrors;
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
    use HasStructuredErrors;

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

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $experiment = Experiment::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['experiment_id']);

        if (! $experiment) {
            return $this->notFoundError('experiment');
        }

        $transitions = ExperimentTransitionMap::allowedTransitions($experiment->status);

        return Response::text(json_encode([
            'experiment_id' => $experiment->id,
            'current_status' => $experiment->status->value,
            'valid_transitions' => array_map(fn ($t) => $t->value, $transitions),
        ]));
    }
}
