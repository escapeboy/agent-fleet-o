<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Listeners;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\AgentChatProtocol\Enums\MessageDirection;
use App\Domain\AgentChatProtocol\Enums\MessageStatus;
use App\Domain\AgentChatProtocol\Enums\MessageType;
use App\Domain\AgentChatProtocol\Events\ChatMessageReceived;
use App\Domain\AgentChatProtocol\Models\AgentChatMessage;
use App\Domain\AgentChatProtocol\Services\ProtocolDispatcher;
use App\Domain\AgentChatProtocol\Services\SessionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExecuteAgentOnChatMessage implements ShouldQueue
{
    public string $queue = 'ai-calls';

    public function __construct(
        private readonly ExecuteAgentAction $executor,
        private readonly SessionManager $sessions,
        private readonly ProtocolDispatcher $dispatcher,
    ) {}

    public function handle(ChatMessageReceived $event): void
    {
        $message = $event->message;
        if ($message->agent_id === null) {
            return;
        }

        $agent = $message->agent;
        if ($agent === null || ! $agent->chat_protocol_enabled) {
            return;
        }

        $content = match ($message->message_type) {
            MessageType::ChatMessage => (string) ($message->payload['content'] ?? ''),
            MessageType::StructuredOutputRequest => (string) ($message->payload['prompt'] ?? ''),
            default => '',
        };

        if ($content === '') {
            return;
        }

        $schema = $message->message_type === MessageType::StructuredOutputRequest
            ? (array) ($message->payload['schema'] ?? [])
            : [];

        try {
            $input = ['prompt' => $content];
            if ($schema !== []) {
                $input['response_schema'] = $schema;
            }

            $result = $this->executor->execute(
                agent: $agent,
                input: $input,
                teamId: (string) $agent->team_id,
                userId: (string) ($agent->owner_user_id ?? '00000000-0000-0000-0000-000000000000'),
            );

            $this->sendReply($message, $result, $schema !== []);
        } catch (\Throwable $e) {
            Log::error('Agent chat protocol execution failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
            $message->forceFill([
                'status' => MessageStatus::Failed,
                'error' => substr($e->getMessage(), 0, 500),
            ])->save();
        }
    }

    private function sendReply(AgentChatMessage $inbound, array $result, bool $structured): void
    {
        $session = $inbound->session;
        if ($session === null) {
            return;
        }

        $replyType = $structured ? MessageType::StructuredOutputResponse : MessageType::ChatMessage;
        $responseUrl = $inbound->payload['response_url'] ?? null;

        $payload = $structured
            ? [
                'msg_id' => Str::uuid7()->toString(),
                'in_reply_to' => $inbound->msg_id,
                'session_id' => (string) $session->session_token,
                'from' => $inbound->to_identifier,
                'to' => $inbound->from_identifier,
                'output' => $result['output'] ?? $result,
                'timestamp' => now()->toIso8601String(),
                'error' => false,
            ]
            : [
                'msg_id' => Str::uuid7()->toString(),
                'in_reply_to' => $inbound->msg_id,
                'session_id' => (string) $session->session_token,
                'from' => $inbound->to_identifier,
                'to' => $inbound->from_identifier,
                'content' => (string) ($result['output'] ?? ''),
                'timestamp' => now()->toIso8601String(),
            ];

        $outbound = AgentChatMessage::create([
            'id' => Str::uuid7()->toString(),
            'team_id' => $inbound->team_id,
            'session_id' => $session->id,
            'agent_id' => $inbound->agent_id,
            'external_agent_id' => null,
            'direction' => MessageDirection::Outbound,
            'message_type' => $replyType,
            'msg_id' => $payload['msg_id'],
            'in_reply_to' => $inbound->msg_id,
            'from_identifier' => $payload['from'],
            'to_identifier' => $payload['to'],
            'status' => MessageStatus::Pending,
            'payload' => $payload,
        ]);

        $this->sessions->touch($session);

        if ($responseUrl !== null) {
            try {
                Http::timeout(10)->acceptJson()->post((string) $responseUrl, $payload);
                $outbound->forceFill(['status' => MessageStatus::Delivered])->save();
            } catch (\Throwable $e) {
                $outbound->forceFill([
                    'status' => MessageStatus::Failed,
                    'error' => substr($e->getMessage(), 0, 500),
                ])->save();
            }
        } else {
            $outbound->forceFill(['status' => MessageStatus::Delivered])->save();
        }
    }
}
