<?php

declare(strict_types=1);

namespace App\Mcp\Tools\AgentChatProtocol;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Enums\AgentChatVisibility;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[AssistantTool('read')]
class A2aServerStatusTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'a2a_server_status';

    protected string $description = 'Report whether this team\'s agents are reachable by external A2A peers: the server flag, and per agent its AgentCard URL, JSON-RPC endpoint, visibility, and whether the card is published publicly. Use this to hand an external agent the right URL, or to work out why a peer cannot reach an agent.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('Optional agent UUID to report on a single agent instead of all chat-enabled ones.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['agent_id' => 'nullable|string']);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $query = Agent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('chat_protocol_enabled', true);

        if (! empty($validated['agent_id'])) {
            $query->where('id', $validated['agent_id']);
        }

        $agents = [];
        foreach ($query->limit(200)->get() as $agent) {
            $visibility = AgentChatVisibility::normalize($agent->getAttribute('chat_protocol_visibility'));
            $public = $visibility?->allowsPublicManifest() ?? false;
            $slug = $agent->chat_protocol_slug ?? $agent->id;
            $rpcUrl = url('/api/v1/agents/'.$agent->id.'/a2a');

            $agents[] = [
                'agent_id' => $agent->id,
                'name' => $agent->name,
                'visibility' => $visibility?->value,
                'rpc_url' => $rpcUrl,
                'agent_card_url' => $public
                    ? url('/.well-known/agents/'.$slug.'/agent-card.json')
                    : $rpcUrl,
                'card_is_public' => $public,
                // Public agents authenticate peers with a JWT signed by the
                // agent secret; team/private ones need a same-team Sanctum token.
                'auth' => $public ? 'jwt_hs256_agent_secret' : 'sanctum_same_team',
                'peer_ready' => $public && ! empty($agent->chat_protocol_secret),
            ];
        }

        return Response::text((string) json_encode([
            'server_enabled' => (bool) config('agent_chat.a2a.server_enabled', false),
            'methods' => ['message/send', 'tasks/get'],
            'streaming' => false,
            'agents' => $agents,
        ]));
    }
}
