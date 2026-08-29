<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Enums\AgentChatVisibility;
use App\Domain\AgentChatProtocol\Services\HmacJwtVerifier;
use Illuminate\Http\Request;

/**
 * Inbound agent-chat authorization, shared by every transport that lets an
 * outside caller reach one of our agents (REST chat, A2A JSON-RPC).
 *
 * It lives in one place deliberately: the rule that private/team agents need a
 * same-team Sanctum token while public/marketplace agents need a JWT signed
 * with the agent's own secret is the entire tenant boundary for this surface.
 * Two transports maintaining their own copy is how one of them quietly drifts
 * into being the weak one.
 */
trait ResolvesChatProtocolAgent
{
    protected function resolveChatProtocolAgent(Request $request, string $agentId, HmacJwtVerifier $jwt): Agent
    {
        /** @var Agent|null $agent */
        $agent = Agent::withoutGlobalScopes()
            ->where('id', $agentId)
            ->where('chat_protocol_enabled', true)
            ->first();

        if ($agent === null) {
            abort(404, 'Agent not available on the chat protocol');
        }

        $visibility = AgentChatVisibility::normalize($agent->getAttribute('chat_protocol_visibility'));
        if ($visibility !== null && $visibility->requiresSanctum()) {
            $user = $request->user();
            if ($user === null) {
                abort(401, 'Sanctum token required');
            }
            if ((string) ($user->current_team_id ?? '') !== (string) $agent->team_id) {
                abort(404, 'Agent not available on the chat protocol');
            }
        } elseif ($visibility !== null && $visibility->allowsPublicManifest()) {
            $this->verifyChatProtocolJwt($request, $agent, $jwt);
        }

        if ($request->hasHeader('content-length') && (int) $request->header('content-length') > (int) config('agent_chat.inbound.max_body_bytes', 512_000)) {
            abort(413, 'Payload too large');
        }

        return $agent;
    }

    private function verifyChatProtocolJwt(Request $request, Agent $agent, HmacJwtVerifier $jwt): void
    {
        $auth = $request->bearerToken();
        if ($auth === null) {
            abort(401, 'Bearer JWT required for public agent');
        }
        if (empty($agent->chat_protocol_secret)) {
            abort(401, 'Agent secret not configured');
        }
        try {
            $jwt->verify($auth, (string) $agent->chat_protocol_secret);
        } catch (\Throwable $e) {
            abort(401, 'Invalid JWT: '.$e->getMessage());
        }
    }

    protected function enforceChatProtocolRate(Request $request, Agent $agent): void
    {
        $perRemote = (int) config('agent_chat.inbound.rate_limit_per_remote_per_minute', 60);
        $perAgent = (int) config('agent_chat.inbound.rate_limit_per_agent_per_minute', 300);

        $remoteId = (string) ($request->input('from') ?? $request->ip());
        $remoteKey = 'acp:remote:'.md5($remoteId).':'.$agent->id;
        $agentKey = 'acp:agent:'.$agent->id;

        $remoteCount = (int) cache()->get($remoteKey, 0);
        $agentCount = (int) cache()->get($agentKey, 0);

        if ($remoteCount >= $perRemote || $agentCount >= $perAgent) {
            abort(429, 'Rate limit exceeded');
        }

        cache()->put($remoteKey, $remoteCount + 1, 60);
        cache()->put($agentKey, $agentCount + 1, 60);
    }
}
