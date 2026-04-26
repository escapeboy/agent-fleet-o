<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Actions\DryRunAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
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
            'model_override' => $schema->string()
                ->description('Optional override for the model (e.g. "claude-opus-4-6"). Defaults to the agent\'s saved model. Provider stays the same.'),
            'temperature_override' => $schema->number()
                ->description('Optional temperature override (0.0–2.0). Defaults to agent.default_temperature config (0.7).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'input_message' => 'required|string|min:1|max:10000',
            'system_prompt_override' => 'nullable|string|max:50000',
            'model_override' => 'nullable|string|max:200',
            'temperature_override' => 'nullable|numeric|min:0|max:2',
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

        // Per-team daily quota (config/self-service.dry_run.daily_cap).
        // Counter keyed by UTC date; ttl 26h covers timezone overhang.
        $dailyCap = (int) config('self-service.dry_run.daily_cap', 200);
        if ($dailyCap > 0) {
            $key = sprintf(
                'self_service:dry_run:count:%s:%s',
                $teamId,
                now()->utc()->toDateString(),
            );
            $current = (int) (Cache::get($key) ?? 0);
            if ($current >= $dailyCap) {
                return $this->errorResponse(
                    \App\Mcp\ErrorCode::ResourceExhausted,
                    sprintf(
                        'Daily dry-run cap reached (%d/%d). The counter resets at 00:00 UTC.',
                        $current,
                        $dailyCap,
                    ),
                );
            }
            // Increment with a 26h TTL (handles timezone-overhang refresh).
            Cache::put($key, $current + 1, now()->addHours(26));
        }

        try {
            $result = app(DryRunAgentAction::class)->execute(
                agent: $agent,
                userMessage: $validated['input_message'],
                userId: (string) $userId,
                systemPromptOverride: $validated['system_prompt_override'] ?? null,
                modelOverride: $validated['model_override'] ?? null,
                temperatureOverride: isset($validated['temperature_override'])
                    ? (float) $validated['temperature_override']
                    : null,
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
