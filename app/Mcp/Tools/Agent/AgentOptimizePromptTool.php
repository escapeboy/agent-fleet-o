<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\Agent;
use App\Domain\Evolution\Actions\OptimizeAgentPromptAction;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
// @mcp-cross-tenant transitive-via-agent — agent_id team-verified upstream
class AgentOptimizePromptTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_optimize_prompt';

    protected string $description = 'Run GEPA prompt optimisation for an agent: generate candidate goal/backstory variants, score each against the agent\'s eval dataset (config.eval_gate_dataset_id), and create an EvolutionProposal for the best variant ONLY if it beats the current baseline. Proposal-only — review and apply to promote. Requires agent.prompt_optimizer.enabled and a configured eval dataset.';

    public function __construct(
        private readonly OptimizeAgentPromptAction $optimize,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'population_size' => $schema->integer()
                ->description('Number of candidate variants to generate and score (1-8, default from config).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'population_size' => 'nullable|integer|min:1|max:8',
        ]);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $agent = Agent::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['agent_id']);
        if (! $agent) {
            return $this->notFoundError('agent');
        }

        $result = $this->optimize->execute($agent, $validated['population_size'] ?? null);

        return Response::text(json_encode(array_merge(['agent_id' => $agent->id], $result)));
    }
}
