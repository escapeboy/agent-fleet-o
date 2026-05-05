<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Actions\RecordAgentConfigRevisionAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class AgentUpdateTool extends Tool
{
    use HasStructuredErrors;

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
            'environment' => $schema->string()
                ->description('Environment preset that auto-attaches a tool bundle. Options: minimal, coding, browsing, restricted. Pass empty string to remove.')
                ->enum(['', 'minimal', 'coding', 'browsing', 'restricted']),
            'reasoning_effort' => $schema->string()
                ->description('Extended thinking effort (Anthropic). Options: none, low, medium, high, auto. Pass "none" to disable.')
                ->enum(['none', 'low', 'medium', 'high', 'auto']),
            'use_tool_search' => $schema->boolean()
                ->description('Enable or disable semantic tool auto-discovery. When true, up to tool_search_top_k matching team tools are auto-attached per run.'),
            'tool_search_top_k' => $schema->integer()
                ->description('Maximum tools tool_search will surface per run (1–20). Only applies when use_tool_search=true.'),
            'thinking_budget' => $schema->integer()
                ->description('Anthropic extended thinking budget in tokens (e.g. 1024, 4096, 8192). Only applies when agent provider is "anthropic". Set to 0 to disable. Enables chain-of-thought reasoning visible in experiment steps.'),
            'knowledge_base_id' => $schema->string()
                ->description('UUID of a knowledge base to link. Pass empty string to unlink.'),
            'evaluation_enabled' => $schema->boolean()
                ->description('Enable or disable A/B evaluation for this agent'),
            'evaluation_sample_rate' => $schema->number()
                ->description('Fraction of requests to include in evaluation (0.0 to 1.0)'),
            'heartbeat_definition' => $schema->object()
                ->description('Agent health check config: {enabled: bool, cron: string, prompt: string}. Pass null to clear.'),
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
            'environment' => 'nullable|string|in:,minimal,coding,browsing,restricted',
            'reasoning_effort' => 'nullable|string|in:none,low,medium,high,auto',
            'use_tool_search' => 'nullable|boolean',
            'tool_search_top_k' => 'nullable|integer|min:1|max:20',
            'sandbox_profile' => 'nullable|string',
            'thinking_budget' => 'nullable|integer|min:0|max:100000',
            'knowledge_base_id' => 'nullable|string',
            'evaluation_enabled' => 'nullable|boolean',
            'evaluation_sample_rate' => 'nullable|numeric|min:0|max:1',
            'heartbeat_definition' => 'nullable|array',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $agent = Agent::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['agent_id']);

        if (! $agent) {
            return $this->notFoundError('agent');
        }

        // IDOR guard: verify knowledge_base_id belongs to the team
        $kbId = $validated['knowledge_base_id'] ?? null;
        if ($kbId && $kbId !== '') {
            $kbExists = KnowledgeBase::withoutGlobalScopes()
                ->where('id', $kbId)
                ->where('team_id', $teamId)
                ->exists();
            if (! $kbExists) {
                return $this->notFoundError('knowledge base');
            }
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

        // environment: allow empty string to clear the preset
        if (array_key_exists('environment', $validated) && $validated['environment'] !== null) {
            $data['environment'] = $validated['environment'] === '' ? null : $validated['environment'];
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
                    return $this->invalidArgumentError('sandbox_profile must be a valid JSON object or "null" to clear.');
                }
                $data['sandbox_profile'] = $sandboxProfile;
            }
        }

        // thinking_budget: stored in agent.config JSONB, not as a top-level column
        if (array_key_exists('thinking_budget', $validated) && $validated['thinking_budget'] !== null) {
            $currentConfig = $data['config'] ?? $agent->config ?? [];
            if ((int) $validated['thinking_budget'] === 0) {
                unset($currentConfig['thinking_budget']);
            } else {
                $currentConfig['thinking_budget'] = (int) $validated['thinking_budget'];
            }
            $data['config'] = $currentConfig;
        }

        // reasoning_effort: stored in agent.config JSONB; "none" unsets it
        if (array_key_exists('reasoning_effort', $validated) && $validated['reasoning_effort'] !== null) {
            $currentConfig = $data['config'] ?? $agent->config ?? [];
            if ($validated['reasoning_effort'] === 'none') {
                unset($currentConfig['reasoning_effort']);
            } else {
                $currentConfig['reasoning_effort'] = $validated['reasoning_effort'];
            }
            $data['config'] = $currentConfig;
        }

        // use_tool_search + tool_search_top_k: stored in agent.config JSONB; false clears both
        if (array_key_exists('use_tool_search', $validated) && $validated['use_tool_search'] !== null) {
            $currentConfig = $data['config'] ?? $agent->config ?? [];
            if ($validated['use_tool_search']) {
                $currentConfig['use_tool_search'] = true;
                $topK = (int) ($validated['tool_search_top_k'] ?? $currentConfig['tool_search_top_k'] ?? 5);
                $currentConfig['tool_search_top_k'] = max(1, min(20, $topK));
            } else {
                unset($currentConfig['use_tool_search'], $currentConfig['tool_search_top_k']);
            }
            $data['config'] = $currentConfig;
        } elseif (array_key_exists('tool_search_top_k', $validated) && $validated['tool_search_top_k'] !== null) {
            // top_k alone (without toggling flag): only honor if already enabled
            $currentConfig = $data['config'] ?? $agent->config ?? [];
            if (! empty($currentConfig['use_tool_search'])) {
                $currentConfig['tool_search_top_k'] = max(1, min(20, (int) $validated['tool_search_top_k']));
                $data['config'] = $currentConfig;
            }
        }

        // knowledge_base_id: allow empty string to unlink
        if (array_key_exists('knowledge_base_id', $validated) && $validated['knowledge_base_id'] !== null) {
            $data['knowledge_base_id'] = $validated['knowledge_base_id'] === '' ? null : $validated['knowledge_base_id'];
        }

        // evaluation fields
        if (array_key_exists('evaluation_enabled', $validated) && $validated['evaluation_enabled'] !== null) {
            $data['evaluation_enabled'] = $validated['evaluation_enabled'];
        }
        if (array_key_exists('evaluation_sample_rate', $validated) && $validated['evaluation_sample_rate'] !== null) {
            $data['evaluation_sample_rate'] = $validated['evaluation_sample_rate'];
        }

        // heartbeat_definition
        if (array_key_exists('heartbeat_definition', $validated)) {
            $data['heartbeat_definition'] = $validated['heartbeat_definition'];
        }

        if (empty($data)) {
            return $this->invalidArgumentError('No fields to update. Provide at least one of: name, role, goal, backstory, personality, provider, model, budget_cap_credits, tool_profile, environment, reasoning_effort, use_tool_search, tool_search_top_k, sandbox_profile, thinking_budget, knowledge_base_id, evaluation_enabled, evaluation_sample_rate, heartbeat_definition.');
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
