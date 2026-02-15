<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Actions\CreateAgentAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AgentCreateTool extends Tool
{
    protected string $name = 'agent_create';

    protected string $description = 'Create a new AI agent. Specify name, role, goal, backstory, and optionally provider/model.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Agent name')
                ->required(),
            'role' => $schema->string()
                ->description('Agent role description'),
            'goal' => $schema->string()
                ->description('Agent goal'),
            'backstory' => $schema->string()
                ->description('Agent backstory'),
            'provider' => $schema->string()
                ->description('LLM provider: anthropic, openai, google (default: anthropic)')
                ->enum(['anthropic', 'openai', 'google'])
                ->default('anthropic'),
            'model' => $schema->string()
                ->description('LLM model name (default: claude-sonnet-4-5)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'role' => 'nullable|string',
            'goal' => 'nullable|string',
            'backstory' => 'nullable|string',
            'provider' => 'nullable|string|in:anthropic,openai,google',
            'model' => 'nullable|string|max:100',
        ]);

        try {
            $agent = app(CreateAgentAction::class)->execute(
                name: $validated['name'],
                provider: $validated['provider'] ?? 'anthropic',
                model: $validated['model'] ?? 'claude-sonnet-4-5',
                role: $validated['role'] ?? null,
                goal: $validated['goal'] ?? null,
                backstory: $validated['backstory'] ?? null,
                teamId: auth()->user()->current_team_id,
            );

            return Response::text(json_encode([
                'success' => true,
                'agent_id' => $agent->id,
                'name' => $agent->name,
                'status' => $agent->status->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
