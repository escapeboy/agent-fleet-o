<?php

declare(strict_types=1);

namespace App\Mcp\Tools\AgentChatProtocol;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Actions\PublishAgentManifestAction;
use App\Domain\AgentChatProtocol\Enums\AgentChatVisibility;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[AssistantTool('write')]
class AgentChatManifestPublishTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_chat_manifest_publish';

    protected string $description = 'Enable the Agent Chat Protocol on an agent and publish its manifest at /.well-known/agents/{slug}. Visibility controls who can call it.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('Agent UUID')->required(),
            'visibility' => $schema->string()->description('private | team | marketplace | public')->required(),
            'slug' => $schema->string()->description('Optional custom public slug'),
        ];
    }

    public function handle(Request $request, PublishAgentManifestAction $action): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'visibility' => 'required|in:private,team,marketplace,public',
            'slug' => 'sometimes|string|max:255|regex:/^[a-z0-9-]+$/',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $agent = Agent::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['agent_id']);
        if (! $agent) {
            return $this->notFoundError('agent', $validated['agent_id']);
        }

        $agent = $action->execute(
            agent: $agent,
            visibility: AgentChatVisibility::from($validated['visibility']),
            customSlug: $validated['slug'] ?? null,
        );

        return Response::text(json_encode([
            'agent_id' => $agent->id,
            'chat_protocol_enabled' => true,
            'visibility' => $agent->chat_protocol_visibility?->value,
            'slug' => $agent->chat_protocol_slug,
            'manifest_url' => url('/.well-known/agents/'.$agent->chat_protocol_slug),
            'secret' => $agent->chat_protocol_secret,
        ]));
    }
}
