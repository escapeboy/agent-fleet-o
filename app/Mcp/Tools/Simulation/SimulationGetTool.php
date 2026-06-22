<?php

namespace App\Mcp\Tools\Simulation;

use App\Domain\Simulation\Models\SimulationRun;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class SimulationGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'simulation_get';

    protected string $description = 'Get a simulation run: status, the pass/fail aggregate matrix, and per-persona transcript verdicts.';

    public function shouldRegister(): bool
    {
        return (bool) config('simulation.enabled', false);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()->description('The simulation run UUID')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['run_id' => 'required|string']);

        $run = SimulationRun::with(['transcripts.persona'])->find($validated['run_id']);

        if (! $run) {
            return $this->notFoundError('simulation run');
        }

        return Response::text((string) json_encode([
            'run_id' => $run->id,
            'status' => $run->status->value,
            'aggregate' => $run->aggregate,
            'error' => $run->error,
            'transcripts' => $run->transcripts->map(fn ($t) => [
                'persona' => $t->persona?->name,
                'verdict' => $t->verdict,
                'failed_turn_index' => $t->failed_turn_index,
                'scores' => $t->scores,
            ])->toArray(),
        ]));
    }
}
