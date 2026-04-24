<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Services;

use App\Domain\AgentChatProtocol\Enums\MessageType;
use App\Domain\AgentChatProtocol\Exceptions\InvalidProtocolMessageException;
use Carbon\Carbon;

class MessageSchemaValidator
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public function validate(MessageType $type, array $payload): void
    {
        $this->assertCommonFields($payload);

        match ($type) {
            MessageType::ChatMessage => $this->assertChatMessage($payload),
            MessageType::ChatAcknowledgement => $this->assertAck($payload),
            MessageType::StructuredOutputRequest => $this->assertStructuredRequest($payload),
            MessageType::StructuredOutputResponse => $this->assertStructuredResponse($payload),
        };
    }

    private function assertCommonFields(array $payload): void
    {
        $requiredFields = ['msg_id', 'session_id', 'timestamp', 'from', 'to'];
        foreach ($requiredFields as $field) {
            if (! array_key_exists($field, $payload) || $payload[$field] === '') {
                throw new InvalidProtocolMessageException("Missing required field: {$field}");
            }
        }

        if (! preg_match(self::UUID_REGEX, (string) $payload['msg_id'])) {
            throw new InvalidProtocolMessageException('msg_id must be a valid UUID');
        }

        if (! preg_match(self::UUID_REGEX, (string) $payload['session_id'])) {
            throw new InvalidProtocolMessageException('session_id must be a valid UUID');
        }

        try {
            $timestamp = Carbon::parse((string) $payload['timestamp']);
        } catch (\Throwable $e) {
            throw new InvalidProtocolMessageException('timestamp must be ISO8601: '.$e->getMessage());
        }

        $toleranceSec = (int) config('agent_chat.inbound.clock_skew_tolerance_seconds', 300);
        if ($timestamp->isFuture() && abs($timestamp->diffInSeconds(now(), false)) > $toleranceSec) {
            throw new InvalidProtocolMessageException("timestamp exceeds clock skew tolerance of {$toleranceSec}s");
        }
    }

    private function assertChatMessage(array $payload): void
    {
        if (! array_key_exists('content', $payload)) {
            throw new InvalidProtocolMessageException('chat_message requires content');
        }
    }

    private function assertAck(array $payload): void
    {
        foreach (['ack_for', 'status'] as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new InvalidProtocolMessageException("chat_acknowledgement requires {$field}");
            }
        }
    }

    private function assertStructuredRequest(array $payload): void
    {
        foreach (['prompt', 'schema'] as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new InvalidProtocolMessageException("structured_output_request requires {$field}");
            }
        }

        if (! is_array($payload['schema']) || $payload['schema'] === []) {
            throw new InvalidProtocolMessageException('schema must be a non-empty object');
        }
    }

    private function assertStructuredResponse(array $payload): void
    {
        if (! array_key_exists('in_reply_to', $payload)) {
            throw new InvalidProtocolMessageException('structured_output_response requires in_reply_to');
        }
    }
}
