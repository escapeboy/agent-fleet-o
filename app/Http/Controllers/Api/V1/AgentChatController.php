<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Enums\AckStatus;
use App\Domain\AgentChatProtocol\Exceptions\InvalidProtocolMessageException;
use App\Domain\AgentChatProtocol\Services\HmacJwtVerifier;
use App\Domain\AgentChatProtocol\Services\ProtocolReceiver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Agent Chat Protocol
 */
class AgentChatController extends Controller
{
    public function __construct(
        private readonly ProtocolReceiver $receiver,
        private readonly HmacJwtVerifier $jwt,
    ) {}

    public function chat(Request $request, string $agentId): JsonResponse
    {
        $agent = $this->resolveAgent($request, $agentId);

        $this->enforceRate($request, $agent);

        $payload = $request->all();
        try {
            $message = $this->receiver->receiveChat($agent, $payload);
        } catch (InvalidProtocolMessageException $e) {
            return response()->json(['error' => $e->getMessage()], $this->errorStatus($e));
        }

        $ack = $this->receiver->ack($message, AckStatus::Received);

        return response()->json([
            'ack' => $ack->toArray(),
            'message_id' => $message->id,
            'status' => 'accepted',
        ], 202);
    }

    public function structured(Request $request, string $agentId): JsonResponse
    {
        $agent = $this->resolveAgent($request, $agentId);
        $this->enforceRate($request, $agent);

        try {
            $message = $this->receiver->receiveStructured($agent, $request->all());
        } catch (InvalidProtocolMessageException $e) {
            return response()->json(['error' => $e->getMessage()], $this->errorStatus($e));
        }

        $ack = $this->receiver->ack($message, AckStatus::Received);

        return response()->json([
            'ack' => $ack->toArray(),
            'message_id' => $message->id,
            'status' => 'accepted',
        ], 202);
    }

    public function ack(Request $request, string $agentId): JsonResponse
    {
        $agent = $this->resolveAgent($request, $agentId);
        // Accept ACKs for outbound messages from this agent; store as inbound audit.
        $payload = $request->all();

        return response()->json(['status' => 'ack_received'], 200);
    }

    private function resolveAgent(Request $request, string $agentId): Agent
    {
        /** @var Agent|null $agent */
        $agent = Agent::withoutGlobalScopes()
            ->where('id', $agentId)
            ->where('chat_protocol_enabled', true)
            ->first();

        if ($agent === null) {
            abort(404, 'Agent not available on the chat protocol');
        }

        $visibility = $agent->chat_protocol_visibility;
        if ($visibility !== null && $visibility->requiresSanctum()) {
            $user = $request->user();
            if ($user === null) {
                abort(401, 'Sanctum token required');
            }
            if ((string) ($user->current_team_id ?? '') !== (string) $agent->team_id) {
                abort(404, 'Agent not available on the chat protocol');
            }
        } elseif ($visibility !== null && $visibility->allowsPublicManifest()) {
            $this->verifyJwt($request, $agent);
        }

        if ($request->hasHeader('content-length') && (int) $request->header('content-length') > (int) config('agent_chat.inbound.max_body_bytes', 512_000)) {
            abort(413, 'Payload too large');
        }

        return $agent;
    }

    private function verifyJwt(Request $request, Agent $agent): void
    {
        $auth = $request->bearerToken();
        if ($auth === null) {
            abort(401, 'Bearer JWT required for public agent');
        }
        if (empty($agent->chat_protocol_secret)) {
            abort(401, 'Agent secret not configured');
        }
        try {
            $this->jwt->verify($auth, (string) $agent->chat_protocol_secret);
        } catch (\Throwable $e) {
            abort(401, 'Invalid JWT: '.$e->getMessage());
        }
    }

    private function enforceRate(Request $request, Agent $agent): void
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

    private function errorStatus(InvalidProtocolMessageException $e): int
    {
        return str_contains($e->getMessage(), 'Duplicate') ? 409 : 400;
    }
}
