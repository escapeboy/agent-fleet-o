<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Actions;

use App\Domain\AgentChatProtocol\DTOs\StructuredRequestDTO;
use App\Domain\AgentChatProtocol\Enums\MessageDirection;
use App\Domain\AgentChatProtocol\Enums\MessageStatus;
use App\Domain\AgentChatProtocol\Enums\MessageType;
use App\Domain\AgentChatProtocol\Models\AgentChatMessage;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\AgentChatProtocol\Services\ProtocolDispatcher;
use App\Domain\AgentChatProtocol\Services\SessionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DispatchStructuredRequestAction
{
    public function __construct(
        private readonly ProtocolDispatcher $dispatcher,
        private readonly SessionManager $sessions,
    ) {}

    public function execute(
        ExternalAgent $externalAgent,
        string $prompt,
        array $schema,
        ?string $sessionToken = null,
        ?string $from = null,
    ): array {
        $sessionToken ??= $this->sessions->generateToken();
        $session = $this->sessions->resolve(
            teamId: (string) $externalAgent->team_id,
            sessionToken: $sessionToken,
            externalAgent: $externalAgent,
        );

        $dto = new StructuredRequestDTO(
            msgId: Str::uuid7()->toString(),
            sessionId: $sessionToken,
            from: $from ?? 'fleetq:team:'.$externalAgent->team_id,
            to: $externalAgent->slug,
            prompt: $prompt,
            schema: $schema,
            timestamp: now()->toIso8601String(),
        );

        $outbound = DB::transaction(function () use ($externalAgent, $session, $dto) {
            $msg = AgentChatMessage::create([
                'id' => Str::uuid7()->toString(),
                'team_id' => $externalAgent->team_id,
                'session_id' => $session->id,
                'agent_id' => null,
                'external_agent_id' => $externalAgent->id,
                'direction' => MessageDirection::Outbound,
                'message_type' => MessageType::StructuredOutputRequest,
                'msg_id' => $dto->msgId,
                'from_identifier' => $dto->from,
                'to_identifier' => $dto->to,
                'status' => MessageStatus::Pending,
                'payload' => $dto->toArray(),
            ]);
            $this->sessions->touch($session);

            return $msg;
        });

        try {
            $response = $this->dispatcher->sendStructuredRequest($externalAgent, $dto);
            $outbound->forceFill(['status' => MessageStatus::Delivered])->save();

            AgentChatMessage::create([
                'id' => Str::uuid7()->toString(),
                'team_id' => $externalAgent->team_id,
                'session_id' => $session->id,
                'agent_id' => null,
                'external_agent_id' => $externalAgent->id,
                'direction' => MessageDirection::Inbound,
                'message_type' => MessageType::StructuredOutputResponse,
                'msg_id' => (string) ($response['msg_id'] ?? Str::uuid7()->toString()),
                'in_reply_to' => $dto->msgId,
                'from_identifier' => (string) ($response['from'] ?? $externalAgent->slug),
                'to_identifier' => $dto->from,
                'status' => MessageStatus::Delivered,
                'payload' => $response,
            ]);
            $this->sessions->touch($session);

            return [
                'output' => $response['output'] ?? null,
                'error' => (bool) ($response['error'] ?? false),
                'session_id' => $session->id,
                'session_token' => $sessionToken,
                'remote_response' => $response,
            ];
        } catch (\Throwable $e) {
            $outbound->forceFill([
                'status' => MessageStatus::Failed,
                'error' => substr($e->getMessage(), 0, 500),
            ])->save();
            throw $e;
        }
    }
}
