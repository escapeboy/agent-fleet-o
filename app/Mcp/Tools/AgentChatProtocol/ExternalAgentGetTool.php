<?php

declare(strict_types=1);

namespace App\Mcp\Tools\AgentChatProtocol;

use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class ExternalAgentGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'external_agent_get';

    protected string $description = 'Get full details of a registered remote agent including its cached manifest.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'external_agent_id' => $schema->string()->description('External agent UUID')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['external_agent_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $agent = ExternalAgent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['external_agent_id']);
        if (! $agent) {
            return $this->notFoundError('external_agent', $validated['external_agent_id']);
        }

        return Response::text(json_encode([
            'id' => $agent->id,
            'name' => $agent->name,
            'slug' => $agent->slug,
            'description' => $agent->description,
            'endpoint_url' => $agent->endpoint_url,
            'manifest_url' => $agent->manifest_url,
            'manifest_cached' => $agent->manifest_cached,
            'manifest_fetched_at' => $agent->manifest_fetched_at?->toIso8601String(),
            'status' => $agent->status->value,
            'protocol_version' => $agent->protocol_version,
            'capabilities' => $agent->capabilities,
            'last_call_at' => $agent->last_call_at?->toIso8601String(),
            'last_success_at' => $agent->last_success_at?->toIso8601String(),
            'last_error' => $agent->last_error,
            'credential_id' => $agent->credential_id,
        ]));
    }
}
