<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\DTOs\ChatAckDTO;
use App\Domain\AgentChatProtocol\DTOs\ChatMessageDTO;
use App\Domain\AgentChatProtocol\DTOs\StructuredRequestDTO;
use App\Domain\AgentChatProtocol\Enums\AckStatus;
use App\Domain\AgentChatProtocol\Enums\MessageDirection;
use App\Domain\AgentChatProtocol\Enums\MessageStatus;
use App\Domain\AgentChatProtocol\Enums\MessageType;
use App\Domain\AgentChatProtocol\Events\ChatMessageReceived;
use App\Domain\AgentChatProtocol\Exceptions\InvalidProtocolMessageException;
use App\Domain\AgentChatProtocol\Models\AgentChatMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProtocolReceiver
{
    public function __construct(
        private readonly MessageSchemaValidator $validator,
        private readonly SessionManager $sessions,
    ) {}

    public function receiveChat(Agent $agent, array $payload): AgentChatMessage
    {
        $this->validator->validate(MessageType::ChatMessage, $payload);
        $dto = ChatMessageDTO::fromArray($payload);

        $this->guardReplay($dto->msgId);

        $message = DB::transaction(function () use ($agent, $dto, $payload) {
            $session = $this->sessions->resolve(
                teamId: (string) $agent->team_id,
                sessionToken: $dto->sessionId,
                agent: $agent,
            );

            $message = AgentChatMessage::create([
                'id' => Str::uuid7()->toString(),
                'team_id' => $agent->team_id,
                'session_id' => $session->id,
                'agent_id' => $agent->id,
                'external_agent_id' => null,
                'direction' => MessageDirection::Inbound,
                'message_type' => MessageType::ChatMessage,
                'msg_id' => $dto->msgId,
                'in_reply_to' => $dto->inReplyTo,
                'from_identifier' => $dto->from,
                'to_identifier' => $dto->to,
                'status' => MessageStatus::Delivered,
                'payload' => $payload,
            ]);

            $this->sessions->touch($session);

            return $message;
        });

        ChatMessageReceived::dispatch($message);

        return $message;
    }

    public function receiveStructured(Agent $agent, array $payload): AgentChatMessage
    {
        $this->validator->validate(MessageType::StructuredOutputRequest, $payload);
        $dto = StructuredRequestDTO::fromArray($payload);

        $this->guardReplay($dto->msgId);

        $message = DB::transaction(function () use ($agent, $dto, $payload) {
            $session = $this->sessions->resolve(
                teamId: (string) $agent->team_id,
                sessionToken: $dto->sessionId,
                agent: $agent,
            );

            $message = AgentChatMessage::create([
                'id' => Str::uuid7()->toString(),
                'team_id' => $agent->team_id,
                'session_id' => $session->id,
                'agent_id' => $agent->id,
                'external_agent_id' => null,
                'direction' => MessageDirection::Inbound,
                'message_type' => MessageType::StructuredOutputRequest,
                'msg_id' => $dto->msgId,
                'in_reply_to' => $dto->inReplyTo,
                'from_identifier' => $dto->from,
                'to_identifier' => $dto->to,
                'status' => MessageStatus::Delivered,
                'payload' => $payload,
            ]);

            $this->sessions->touch($session);

            return $message;
        });

        ChatMessageReceived::dispatch($message);

        return $message;
    }

    public function ack(AgentChatMessage $message, AckStatus $status, ?string $error = null): ChatAckDTO
    {
        return new ChatAckDTO(
            msgId: Str::uuid7()->toString(),
            ackFor: (string) $message->msg_id,
            sessionId: (string) $message->session_id,
            status: $status,
            timestamp: now()->toIso8601String(),
            error: $error,
        );
    }

    private function guardReplay(string $msgId): void
    {
        $windowHours = (int) config('agent_chat.inbound.replay_window_hours', 24);
        $existing = AgentChatMessage::withoutGlobalScopes()
            ->where('msg_id', $msgId)
            ->where('created_at', '>=', now()->subHours($windowHours))
            ->exists();

        if ($existing) {
            throw new InvalidProtocolMessageException("Duplicate msg_id {$msgId} within replay window of {$windowHours}h");
        }
    }
}
