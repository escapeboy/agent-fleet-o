<?php

namespace App\Mcp\Tools\Simulation;

use App\Domain\Simulation\Enums\SimulationStatus;
use App\Domain\Simulation\Jobs\ExecuteSimulationRunJob;
use App\Domain\Simulation\Models\SimulationRun;
use App\Domain\Simulation\Models\SimulationSuite;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class SimulationRunTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'simulation_run';

    protected string $description = 'Dispatch a simulation run for a suite — drives every persona through a scored multi-turn conversation against the target agent. Returns the run id to poll with simulation_get.';

    public function shouldRegister(): bool
    {
        return (bool) config('simulation.enabled', false);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->string()->description('The simulation suite UUID')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['suite_id' => 'required|string']);

        $suite = SimulationSuite::find($validated['suite_id']);

        if (! $suite) {
            return $this->notFoundError('simulation suite');
        }

        if ($suite->personas()->count() === 0) {
            return $this->failedPreconditionError('Suite has no personas — run simulation_personas_generate first.');
        }

        $run = SimulationRun::create([
            'suite_id' => $suite->id,
            'status' => SimulationStatus::Pending,
        ]);

        ExecuteSimulationRunJob::dispatch($run->id, $suite->team_id);

        return Response::text((string) json_encode([
            'run_id' => $run->id,
            'status' => $run->status->value,
        ]));
    }
}
