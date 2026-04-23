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

#[AssistantTool('write')]
class ExternalAgentUpdateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'external_agent_update';

    protected string $description = 'Update a registered remote agent (name, endpoint, credential, status).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'external_agent_id' => $schema->string()->description('External agent UUID')->required(),
            'name' => $schema->string()->description('New display name'),
            'endpoint_url' => $schema->string()->description('New endpoint URL'),
            'credential_id' => $schema->string()->description('New credential UUID or null'),
            'description' => $schema->string()->description('New description'),
            'status' => $schema->string()->description('New status: active|paused|disabled'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'external_agent_id' => 'required|string',
            'name' => 'sometimes|string|max:255',
            'endpoint_url' => 'sometimes|url|max:2048',
            'credential_id' => 'sometimes|nullable|uuid',
            'description' => 'sometimes|nullable|string|max:1000',
            'status' => 'sometimes|in:active,paused,disabled',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $agent = ExternalAgent::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['external_agent_id']);
        if (! $agent) {
            return $this->notFoundError('external_agent', $validated['external_agent_id']);
        }

        $agent->update(array_filter(
            array_intersect_key($validated, array_flip(['name', 'endpoint_url', 'credential_id', 'description', 'status'])),
            fn ($v) => $v !== null,
        ));

        return Response::text(json_encode([
            'id' => $agent->id,
            'updated' => true,
            'status' => $agent->status->value,
        ]));
    }
}
