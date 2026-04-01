<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Agent\Actions\CreateAgentAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CreateAgentTool implements Tool
{
    public function name(): string
    {
        return 'create_agent';
    }

    public function description(): string
    {
        return 'Create a new AI agent';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Agent name'),
            'role' => $schema->string()->description('Agent role description'),
            'goal' => $schema->string()->description('Agent goal'),
            'backstory' => $schema->string()->description('Agent backstory'),
            'provider' => $schema->string()->description('LLM provider (anthropic, openai, google). Default: anthropic'),
            'model' => $schema->string()->description('LLM model name. Default: claude-sonnet-4-5'),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $agent = app(CreateAgentAction::class)->execute(
                name: $request->get('name'),
                provider: $request->get('provider', 'anthropic'),
                model: $request->get('model', 'claude-sonnet-4-5'),
                role: $request->get('role'),
                goal: $request->get('goal'),
                backstory: $request->get('backstory'),
                teamId: auth()->user()->current_team_id,
            );

            return json_encode([
                'success' => true,
                'agent_id' => $agent->id,
                'name' => $agent->name,
                'status' => $agent->status->value,
                'url' => route('agents.show', $agent),
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
