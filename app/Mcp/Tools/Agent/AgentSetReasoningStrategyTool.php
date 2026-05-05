<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Enums\AgentReasoningStrategy;
use App\Domain\Agent\Models\Agent;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class AgentSetReasoningStrategyTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_set_reasoning_strategy';

    protected string $description = 'Set the reasoning strategy for an agent. Strategies shape how the agent thinks before acting. Options: function_calling (default), react (Reason+Act), chain_of_thought, plan_and_execute, tree_of_thought.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'strategy' => $schema->string()
                ->description('Reasoning strategy: function_calling | react | chain_of_thought | plan_and_execute | tree_of_thought')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'strategy' => 'required|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $strategy = AgentReasoningStrategy::tryFrom($validated['strategy']);
        if (! $strategy) {
            $valid = implode(', ', array_column(AgentReasoningStrategy::cases(), 'value'));

            return $this->invalidArgumentError("Invalid strategy '{$validated['strategy']}'. Valid: {$valid}");
        }

        $agent = Agent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['agent_id']);

        if (! $agent) {
            return $this->notFoundError('agent');
        }

        $agent->update(['reasoning_strategy' => $strategy]);

        return Response::text(json_encode([
            'success' => true,
            'agent_id' => $agent->id,
            'strategy' => $strategy->value,
            'label' => $strategy->label(),
        ]));
    }
}
