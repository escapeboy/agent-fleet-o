<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Actions\RecordAgentConfigRevisionAction;
use App\Domain\Agent\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AgentUpdateTool extends Tool
{
    protected string $name = 'agent_update';

    protected string $description = 'Update an existing AI agent. Only provided fields will be changed.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'name' => $schema->string()
                ->description('New agent name'),
            'role' => $schema->string()
                ->description('New role description'),
            'goal' => $schema->string()
                ->description('New goal'),
            'backstory' => $schema->string()
                ->description('New backstory'),
            'personality' => $schema->object()
                ->description('New personality traits: {tone, communication_style, traits[], behavioral_rules[], response_format_preference}'),
            'provider' => $schema->string()
                ->description('Override LLM provider (e.g. anthropic, openai, google, claude-code, codex)'),
            'model' => $schema->string()
                ->description('Override LLM model name (e.g. claude-sonnet-4-5, gpt-4o)'),
            'budget_cap_credits' => $schema->integer()
                ->description('Per-agent budget cap in credits. Set to 0 to remove cap.'),
            'data_classification' => $schema->string()
                ->description('Data classification level: public, internal, confidential, restricted. Confidential and restricted agents are routed to local-only providers.')
                ->enum(['public', 'internal', 'confidential', 'restricted']),
            'sandbox_profile' => $schema->string()
                ->description('JSON string defining Docker sandbox profile for per-execution process isolation (enterprise only). Pass "null" to remove. Example: {"image":"python:3.12-alpine","memory":"512m","cpus":"1.0","network":"none","timeout":300}'),
            'tool_profile' => $schema->string()
                ->description('Tool profile restricting tool access. Options: researcher, executor, communicator, analyst, admin, minimal. Pass empty string to remove.'),
            'thinking_budget' => $schema->integer()
                ->description('Anthropic extended thinking budget in tokens (e.g. 1024, 4096, 8192). Only applies when agent provider is "anthropic". Set to 0 to disable. Enables chain-of-thought reasoning visible in experiment steps.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'name' => 'nullable|string|max:255',
            'role' => 'nullable|string',
            'goal' => 'nullable|string',
            'backstory' => 'nullable|string',
            'personality' => 'nullable|array',
            'provider' => 'nullable|string',
            'model' => 'nullable|string',
            'budget_cap_credits' => 'nullable|integer|min:0',
            'data_classification' => 'nullable|string|in:public,internal,confidential,restricted',
            'tool_profile' => 'nullable|string',
            'sandbox_profile' => 'nullable|string',
            'thinking_budget' => 'nullable|integer|min:0|max:100000',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $agent = Agent::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['agent_id']);

        if (! $agent) {
            return Response::error('Agent not found.');
        }

        $data = array_filter([
            'name' => $validated['name'] ?? null,
            'role' => $validated['role'] ?? null,
            'goal' => $validated['goal'] ?? null,
            'backstory' => $validated['backstory'] ?? null,
            'personality' => $validated['personality'] ?? null,
            'provider' => $validated['provider'] ?? null,
            'model' => $validated['model'] ?? null,
            'data_classification' => $validated['data_classification'] ?? null,
        ], fn ($v) => $v !== null);

        // tool_profile: allow empty string to clear, so handle separately from the array_filter above
        if (array_key_exists('tool_profile', $validated) && $validated['tool_profile'] !== null) {
            $data['tool_profile'] = $validated['tool_profile'] === '' ? null : $validated['tool_profile'];
        }

        // budget_cap_credits: allow explicit 0 (removes cap) so we handle separately
        if (array_key_exists('budget_cap_credits', $validated) && $validated['budget_cap_credits'] !== null) {
            $data['budget_cap_credits'] = $validated['budget_cap_credits'] === 0 ? null : $validated['budget_cap_credits'];
        }

        // sandbox_profile: parse JSON string; "null" string clears the profile
        if (isset($validated['sandbox_profile'])) {
            if ($validated['sandbox_profile'] === 'null') {
                $data['sandbox_profile'] = null;
            } else {
                $sandboxProfile = json_decode($validated['sandbox_profile'], true);
                if (! is_array($sandboxProfile)) {
                    return Response::error('sandbox_profile must be a valid JSON object or "null" to clear.');
                }
                $data['sandbox_profile'] = $sandboxProfile;
            }
        }

        // thinking_budget: stored in agent.config JSONB, not as a top-level column
        if (array_key_exists('thinking_budget', $validated) && $validated['thinking_budget'] !== null) {
            $currentConfig = $agent->config ?? [];
            if ((int) $validated['thinking_budget'] === 0) {
                unset($currentConfig['thinking_budget']);
            } else {
                $currentConfig['thinking_budget'] = (int) $validated['thinking_budget'];
            }
            $data['config'] = $currentConfig;
        }

        if (empty($data)) {
            return Response::error('No fields to update. Provide at least one of: name, role, goal, backstory, personality, provider, model, budget_cap_credits, tool_profile, sandbox_profile, thinking_budget.');
        }

        app(RecordAgentConfigRevisionAction::class)->execute(
            agent: $agent,
            newConfig: $data,
            source: 'mcp',
        );

        $agent->update($data);

        return Response::text(json_encode([
            'success' => true,
            'agent_id' => $agent->id,
            'updated_fields' => array_keys($data),
        ]));
    }
}
