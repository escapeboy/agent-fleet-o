<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Actions\CreateAgentAction;
use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Services\ProviderResolver;
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
                ->description('LLM provider key (e.g. anthropic, openai, google, claude-code). Defaults to platform default.'),
            'model' => $schema->string()
                ->description('LLM model name. Defaults to platform default.'),
            'personality' => $schema->object()
                ->description('Agent personality traits: {tone, communication_style, traits[], behavioral_rules[], response_format_preference}'),
            'data_classification' => $schema->string()
                ->description('Data classification level: public, internal, confidential, restricted. Confidential and restricted agents are routed to local-only providers.')
                ->enum(['public', 'internal', 'confidential', 'restricted']),
            'tool_profile' => $schema->string()
                ->description('Tool profile restricting tool access. Options: researcher, executor, communicator, analyst, admin, minimal'),
            'sandbox_profile' => $schema->string()
                ->description('JSON string defining Docker sandbox profile for per-execution process isolation (enterprise only). Example: {"image":"python:3.12-alpine","memory":"512m","cpus":"1.0","network":"none","timeout":300}'),
            'knowledge_base_id' => $schema->string()
                ->description('UUID of a knowledge base to link to this agent for RAG-powered context'),
            'evaluation_enabled' => $schema->boolean()
                ->description('Enable A/B evaluation for this agent')
                ->default(false),
            'evaluation_sample_rate' => $schema->number()
                ->description('Fraction of requests to include in evaluation (0.0 to 1.0). Only used when evaluation_enabled is true.'),
            'heartbeat_definition' => $schema->object()
                ->description('Agent health check config: {enabled: bool, cron: string, prompt: string}'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'role' => 'nullable|string',
            'goal' => 'nullable|string',
            'backstory' => 'nullable|string',
            'provider' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'personality' => 'nullable|array',
            'tool_profile' => 'nullable|string',
            'data_classification' => 'nullable|string|in:public,internal,confidential,restricted',
            'sandbox_profile' => 'nullable|string',
            'knowledge_base_id' => 'nullable|uuid',
            'evaluation_enabled' => 'nullable|boolean',
            'evaluation_sample_rate' => 'nullable|numeric|min:0|max:1',
            'heartbeat_definition' => 'nullable|array',
        ]);
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        // IDOR guard: verify knowledge_base_id belongs to the team
        if (! empty($validated['knowledge_base_id'])) {
            $kbExists = KnowledgeBase::withoutGlobalScopes()
                ->where('id', $validated['knowledge_base_id'])
                ->where('team_id', $teamId)
                ->exists();
            if (! $kbExists) {
                return Response::error('Knowledge base not found or does not belong to this team.');
            }
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
            $team = Team::find($teamId);
            $resolved = app(ProviderResolver::class)->resolve(team: $team);

            $agent = app(CreateAgentAction::class)->execute(
                name: $validated['name'],
                provider: $validated['provider'] ?? $resolved['provider'],
                model: $validated['model'] ?? $resolved['model'],
                role: $validated['role'] ?? null,
                goal: $validated['goal'] ?? null,
                backstory: $validated['backstory'] ?? null,
                teamId: $teamId,
                personality: $validated['personality'] ?? null,
                dataClassification: $validated['data_classification'] ?? null,
            );

            if (! empty($validated['tool_profile'])) {
                $agent->update(['tool_profile' => $validated['tool_profile']]);
            }

            if ($sandboxProfile !== null) {
                $agent->update(['sandbox_profile' => $sandboxProfile]);
            }

            $extraFields = array_filter([
                'knowledge_base_id' => $validated['knowledge_base_id'] ?? null,
                'evaluation_enabled' => $validated['evaluation_enabled'] ?? null,
                'evaluation_sample_rate' => $validated['evaluation_sample_rate'] ?? null,
                'heartbeat_definition' => $validated['heartbeat_definition'] ?? null,
            ], fn ($v) => $v !== null);

            if (! empty($extraFields)) {
                $agent->update($extraFields);
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
