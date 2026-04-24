<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Actions;

use App\Domain\AgentChatProtocol\DTOs\ChatMessageDTO;
use App\Domain\AgentChatProtocol\Enums\MessageDirection;
use App\Domain\AgentChatProtocol\Enums\MessageStatus;
use App\Domain\AgentChatProtocol\Enums\MessageType;
use App\Domain\AgentChatProtocol\Events\ChatMessageDispatched;
use App\Domain\AgentChatProtocol\Models\AgentChatMessage;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\AgentChatProtocol\Services\ProtocolDispatcher;
use App\Domain\AgentChatProtocol\Services\SessionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DispatchChatMessageAction
{
    public function __construct(
        private readonly ProtocolDispatcher $dispatcher,
        private readonly SessionManager $sessions,
    ) {}

    public function execute(
        ExternalAgent $externalAgent,
        string $content,
        ?string $sessionToken = null,
        ?string $from = null,
        array $metadata = [],
    ): array {
        $sessionToken ??= $this->sessions->generateToken();
        $session = $this->sessions->resolve(
            teamId: (string) $externalAgent->team_id,
            sessionToken: $sessionToken,
            externalAgent: $externalAgent,
        );

        $dto = new ChatMessageDTO(
            msgId: Str::uuid7()->toString(),
            sessionId: $sessionToken,
            from: $from ?? 'fleetq:team:'.$externalAgent->team_id,
            to: $externalAgent->slug,
            content: $content,
            timestamp: now()->toIso8601String(),
            metadata: $metadata,
        );

        $outboundMessage = DB::transaction(function () use ($externalAgent, $session, $dto) {
            $msg = AgentChatMessage::create([
                'id' => Str::uuid7()->toString(),
                'team_id' => $externalAgent->team_id,
                'session_id' => $session->id,
                'agent_id' => null,
                'external_agent_id' => $externalAgent->id,
                'direction' => MessageDirection::Outbound,
                'message_type' => MessageType::ChatMessage,
                'msg_id' => $dto->msgId,
                'in_reply_to' => null,
                'from_identifier' => $dto->from,
                'to_identifier' => $dto->to,
                'status' => MessageStatus::Pending,
                'payload' => $dto->toArray(),
            ]);
            $this->sessions->touch($session);

            return $msg;
        });

        try {
            $remoteResponse = $this->dispatcher->sendChat($externalAgent, $dto);
            $outboundMessage->forceFill(['status' => MessageStatus::Delivered])->save();

            ChatMessageDispatched::dispatch($outboundMessage);

            if (isset($remoteResponse['msg_id'])) {
                AgentChatMessage::create([
                    'id' => Str::uuid7()->toString(),
                    'team_id' => $externalAgent->team_id,
                    'session_id' => $session->id,
                    'agent_id' => null,
                    'external_agent_id' => $externalAgent->id,
                    'direction' => MessageDirection::Inbound,
                    'message_type' => MessageType::ChatMessage,
                    'msg_id' => (string) $remoteResponse['msg_id'],
                    'in_reply_to' => $dto->msgId,
                    'from_identifier' => (string) ($remoteResponse['from'] ?? $externalAgent->slug),
                    'to_identifier' => $dto->from,
                    'status' => MessageStatus::Delivered,
                    'payload' => $remoteResponse,
                ]);
                $this->sessions->touch($session);
            }

            return [
                'outbound_message_id' => $outboundMessage->id,
                'session_id' => $session->id,
                'session_token' => $sessionToken,
                'remote_response' => $remoteResponse,
            ];
        } catch (\Throwable $e) {
            $outboundMessage->forceFill([
                'status' => MessageStatus::Failed,
                'error' => substr($e->getMessage(), 0, 500),
            ])->save();
            throw $e;
        }
    }
}
