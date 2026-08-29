<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Enums\AckStatus;
use App\Domain\AgentChatProtocol\Exceptions\InvalidProtocolMessageException;
use App\Domain\AgentChatProtocol\Services\HmacJwtVerifier;
use App\Domain\AgentChatProtocol\Services\ProtocolReceiver;
use App\Http\Controllers\Concerns\ResolvesChatProtocolAgent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Agent Chat Protocol
 */
class AgentChatController extends Controller
{
    use ResolvesChatProtocolAgent;

    public function __construct(
        private readonly ProtocolReceiver $receiver,
        private readonly HmacJwtVerifier $jwt,
    ) {}

    public function chat(Request $request, string $agentId): JsonResponse
    {
        $agent = $this->resolveChatProtocolAgent($request, $agentId, $this->jwt);

        $this->enforceChatProtocolRate($request, $agent);

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
        $agent = $this->resolveChatProtocolAgent($request, $agentId, $this->jwt);
        $this->enforceChatProtocolRate($request, $agent);

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
        $agent = $this->resolveChatProtocolAgent($request, $agentId, $this->jwt);
        // Accept ACKs for outbound messages from this agent; store as inbound audit.
        $payload = $request->all();

        return response()->json(['status' => 'ack_received'], 200);
    }

    private function errorStatus(InvalidProtocolMessageException $e): int
    {
        return str_contains($e->getMessage(), 'Duplicate') ? 409 : 400;
    }
}
