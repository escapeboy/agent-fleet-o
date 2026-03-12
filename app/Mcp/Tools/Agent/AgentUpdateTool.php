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
        ]);

        $agent = Agent::find($validated['agent_id']);

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
        ], fn ($v) => $v !== null);

        // budget_cap_credits: allow explicit 0 (removes cap) so we handle separately
        if (array_key_exists('budget_cap_credits', $validated) && $validated['budget_cap_credits'] !== null) {
            $data['budget_cap_credits'] = $validated['budget_cap_credits'] === 0 ? null : $validated['budget_cap_credits'];
        }

        if (empty($data)) {
            return Response::error('No fields to update. Provide at least one of: name, role, goal, backstory, personality, provider, model, budget_cap_credits.');
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
