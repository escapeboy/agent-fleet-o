<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Actions\DryRunAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Run an agent through one LLM completion without persisting any execution
 * record, artifact, or AiRun row. Useful for testing prompt changes against
 * an example input before promoting them to a real run.
 *
 * Marked IsDestructive because the call costs LLM credits even though no
 * domain rows are written. Available to AssistantTool('write') tier
 * (Member+) since members can already kick off real runs.
 */
#[IsDestructive]
#[AssistantTool('write')]
class AgentDryRunTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_dry_run';

    protected string $description = 'Run an agent against a sample input message via one LLM call WITHOUT persisting an execution record, artifact, or AiRun row. Returns the model output inline. Marketplace-published agents are blocked. Costs credits.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'input_message' => $schema->string()
                ->description('User message to send to the agent')
                ->required(),
            'system_prompt_override' => $schema->string()
                ->description('Optional override for the system prompt — useful for testing prompt changes without saving them to the agent.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'input_message' => 'required|string|min:1|max:10000',
            'system_prompt_override' => 'nullable|string|max:50000',
        ]);

        $teamId = app()->bound('mcp.team_id')
            ? app('mcp.team_id')
            : auth()->user()?->current_team_id;

        if ($teamId === null) {
            return $this->permissionDeniedError('No current team.');
        }

        $userId = auth()->id() ?? \App\Domain\Shared\Models\Team::ownerIdFor((string) $teamId);

        if ($userId === null) {
            return $this->permissionDeniedError('No usable user_id for dry-run.');
        }

        $agent = Agent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['agent_id']);

        if (! $agent) {
            return $this->notFoundError('agent', $validated['agent_id']);
        }

        try {
            $result = app(DryRunAgentAction::class)->execute(
                agent: $agent,
                userMessage: $validated['input_message'],
                userId: (string) $userId,
                systemPromptOverride: $validated['system_prompt_override'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(
                \App\Mcp\ErrorCode::FailedPrecondition,
                $e->getMessage(),
            );
        }

        return Response::text(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
