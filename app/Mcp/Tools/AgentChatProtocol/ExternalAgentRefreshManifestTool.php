<?php

declare(strict_types=1);

namespace App\Mcp\Tools\AgentChatProtocol;

use App\Domain\AgentChatProtocol\Actions\RefreshExternalAgentManifestAction;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[AssistantTool('write')]
class ExternalAgentRefreshManifestTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'external_agent_refresh_manifest';

    protected string $description = 'Re-fetch the remote agent manifest and update cached capabilities. Use when remote agent announces new supported message types.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'external_agent_id' => $schema->string()->description('External agent UUID')->required(),
        ];
    }

    public function handle(Request $request, RefreshExternalAgentManifestAction $action): Response
    {
        $validated = $request->validate(['external_agent_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $agent = ExternalAgent::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['external_agent_id']);
        if (! $agent) {
            return $this->notFoundError('external_agent', $validated['external_agent_id']);
        }

        try {
            $agent = $action->execute($agent);
        } catch (\Throwable $e) {
            return $this->upstreamError($e->getMessage());
        }

        return Response::text(json_encode([
            'id' => $agent->id,
            'manifest_fetched_at' => $agent->manifest_fetched_at?->toIso8601String(),
            'capabilities' => $agent->capabilities,
        ]));
    }
}
