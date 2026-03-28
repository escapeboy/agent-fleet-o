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
            'personality' => $schema->object()
                ->description('Agent personality traits: {tone, communication_style, traits[], behavioral_rules[], response_format_preference}'),
            'data_classification' => $schema->string()
                ->description('Data classification level: public, internal, confidential, restricted. Confidential and restricted agents are routed to local-only providers.')
                ->enum(['public', 'internal', 'confidential', 'restricted']),
            'sandbox_profile' => $schema->string()
                ->description('JSON string defining Docker sandbox profile for per-execution process isolation (enterprise only). Example: {"image":"python:3.12-alpine","memory":"512m","cpus":"1.0","network":"none","timeout":300}'),
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
            'personality' => 'nullable|array',
            'data_classification' => 'nullable|string|in:public,internal,confidential,restricted',
            'sandbox_profile' => 'nullable|string',
        ]);
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        // Parse optional sandbox_profile JSON string into an array
        $sandboxProfile = null;
        if (! empty($validated['sandbox_profile'])) {
            $sandboxProfile = json_decode($validated['sandbox_profile'], true);
            if (! is_array($sandboxProfile)) {
                return Response::error('sandbox_profile must be a valid JSON object.');
            }
        }

        try {
            $agent = app(CreateAgentAction::class)->execute(
                name: $validated['name'],
                provider: $validated['provider'] ?? 'anthropic',
                model: $validated['model'] ?? 'claude-sonnet-4-5',
                role: $validated['role'] ?? null,
                goal: $validated['goal'] ?? null,
                backstory: $validated['backstory'] ?? null,
                teamId: $teamId,
                personality: $validated['personality'] ?? null,
                dataClassification: $validated['data_classification'] ?? null,
            );

            if ($sandboxProfile !== null) {
                $agent->update(['sandbox_profile' => $sandboxProfile]);
            }

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
