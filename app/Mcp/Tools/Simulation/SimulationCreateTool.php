<?php

namespace App\Mcp\Tools\Simulation;

use App\Domain\Agent\Models\Agent;
use App\Domain\Simulation\Actions\GenerateSimulationPersonasAction;
use App\Domain\Simulation\Enums\SimulationTargetType;
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
class SimulationCreateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'simulation_create';

    protected string $description = 'Create a conversation-simulation suite that stress-tests an agent with persona-driven multi-turn conversations. Optionally generate personas immediately.';

    public function shouldRegister(): bool
    {
        return (bool) config('simulation.enabled', false);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Suite name')->required(),
            'target_agent_id' => $schema->string()->description('UUID of the agent to test')->required(),
            'brief' => $schema->string()->description('One-line description of the agent / what to probe'),
            'criteria' => $schema->array()->description('Evaluation criteria keys (default: relevance, correctness)'),
            'persona_count' => $schema->integer()->description('Number of personas to generate (capped at 25)'),
            'max_turns' => $schema->integer()->description('Conversation turns per persona (capped at 8)'),
            'generate_personas' => $schema->boolean()->description('Generate personas immediately from the brief'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'target_agent_id' => 'required|string',
            'brief' => 'nullable|string',
            'criteria' => 'nullable|array',
            'persona_count' => 'nullable|integer|min:1',
            'max_turns' => 'nullable|integer|min:1',
            'generate_personas' => 'nullable|boolean',
        ]);

        $agent = Agent::find($validated['target_agent_id']);

        if (! $agent) {
            return $this->notFoundError('agent');
        }

        $suite = SimulationSuite::create([
            'name' => $validated['name'],
            'target_type' => SimulationTargetType::Agent,
            'target_id' => $agent->id,
            'brief' => $validated['brief'] ?? null,
            'criteria' => $validated['criteria'] ?? config('simulation.defaults.criteria'),
            'persona_count' => min((int) ($validated['persona_count'] ?? config('simulation.defaults.persona_count', 8)), (int) config('simulation.caps.personas', 25)),
            'max_turns' => min((int) ($validated['max_turns'] ?? config('simulation.defaults.max_turns', 6)), (int) config('simulation.caps.turns', 8)),
            'pass_threshold' => config('simulation.defaults.pass_threshold', 6.0),
        ]);

        $personasGenerated = 0;

        if (! empty($validated['generate_personas'])) {
            $personasGenerated = count(app(GenerateSimulationPersonasAction::class)->execute($suite));
        }

        return Response::text((string) json_encode([
            'suite_id' => $suite->id,
            'personas_generated' => $personasGenerated,
        ]));
    }
}
