<?php

namespace App\Mcp\Tools\Simulation;

use App\Domain\Simulation\Actions\GenerateSimulationPersonasAction;
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
class SimulationPersonasGenerateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'simulation_personas_generate';

    protected string $description = 'Generate (or regenerate) test-user personas for a simulation suite from its brief.';

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

        $personas = app(GenerateSimulationPersonasAction::class)->execute($suite);

        return Response::text((string) json_encode([
            'suite_id' => $suite->id,
            'personas_generated' => count($personas),
        ]));
    }
}
