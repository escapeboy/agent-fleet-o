<?php

declare(strict_types=1);

namespace App\Mcp\Tools\AgentChatProtocol;

use App\Domain\AgentChatProtocol\Actions\RegisterExternalAgentAction;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[AssistantTool('write')]
class ExternalAgentCreateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'external_agent_create';

    protected string $description = 'Register a remote agent by endpoint URL. Auto-fetches its Agent Chat Protocol manifest to derive capabilities.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Display name for the remote agent')->required(),
            'endpoint_url' => $schema->string()->description('Base HTTPS URL (e.g. https://host/api/v1/agents/foo)')->required(),
            'manifest_url' => $schema->string()->description('Optional explicit manifest URL; defaults to {endpoint}/manifest'),
            'credential_id' => $schema->string()->description('Optional credential UUID for bearer auth'),
            'description' => $schema->string()->description('Optional description'),
        ];
    }

    public function handle(Request $request, RegisterExternalAgentAction $action): Response
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'endpoint_url' => 'required|url|max:2048',
            'manifest_url' => 'sometimes|url|max:2048',
            'credential_id' => 'sometimes|uuid',
            'description' => 'sometimes|string|max:1000',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        try {
            $agent = $action->execute(
                teamId: (string) $teamId,
                name: $validated['name'],
                endpointUrl: $validated['endpoint_url'],
                manifestUrl: $validated['manifest_url'] ?? null,
                credentialId: $validated['credential_id'] ?? null,
                description: $validated['description'] ?? null,
            );
        } catch (\Throwable $e) {
            return $this->invalidInputError($e->getMessage());
        }

        return Response::text(json_encode([
            'id' => $agent->id,
            'slug' => $agent->slug,
            'status' => $agent->status->value,
            'capabilities' => $agent->capabilities,
            'manifest_fetched_at' => $agent->manifest_fetched_at?->toIso8601String(),
        ]));
    }
}
