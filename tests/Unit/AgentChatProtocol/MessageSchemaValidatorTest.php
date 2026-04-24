<?php

declare(strict_types=1);

namespace Tests\Unit\AgentChatProtocol;

use App\Domain\AgentChatProtocol\Enums\MessageType;
use App\Domain\AgentChatProtocol\Exceptions\InvalidProtocolMessageException;
use App\Domain\AgentChatProtocol\Services\MessageSchemaValidator;
use Illuminate\Support\Str;
use Tests\TestCase;

class MessageSchemaValidatorTest extends TestCase
{
    private MessageSchemaValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new MessageSchemaValidator;
    }

    public function test_accepts_valid_chat_message(): void
    {
        $this->validator->validate(MessageType::ChatMessage, $this->validChatMessage());
        $this->addToAssertionCount(1);
    }

    public function test_rejects_missing_msg_id(): void
    {
        $this->expectException(InvalidProtocolMessageException::class);
        $payload = $this->validChatMessage();
        unset($payload['msg_id']);
        $this->validator->validate(MessageType::ChatMessage, $payload);
    }

    public function test_rejects_invalid_uuid_msg_id(): void
    {
        $this->expectException(InvalidProtocolMessageException::class);
        $payload = $this->validChatMessage();
        $payload['msg_id'] = 'not-a-uuid';
        $this->validator->validate(MessageType::ChatMessage, $payload);
    }

    public function test_rejects_missing_session_id(): void
    {
        $this->expectException(InvalidProtocolMessageException::class);
        $payload = $this->validChatMessage();
        unset($payload['session_id']);
        $this->validator->validate(MessageType::ChatMessage, $payload);
    }

    public function test_rejects_missing_content_on_chat_message(): void
    {
        $this->expectException(InvalidProtocolMessageException::class);
        $payload = $this->validChatMessage();
        unset($payload['content']);
        $this->validator->validate(MessageType::ChatMessage, $payload);
    }

    public function test_rejects_invalid_timestamp(): void
    {
        $this->expectException(InvalidProtocolMessageException::class);
        $payload = $this->validChatMessage();
        $payload['timestamp'] = 'not-a-date';
        $this->validator->validate(MessageType::ChatMessage, $payload);
    }

    public function test_structured_request_requires_prompt_and_schema(): void
    {
        $this->expectException(InvalidProtocolMessageException::class);
        $payload = $this->validChatMessage();
        unset($payload['content']);
        $this->validator->validate(MessageType::StructuredOutputRequest, $payload);
    }

    public function test_structured_request_rejects_empty_schema(): void
    {
        $this->expectException(InvalidProtocolMessageException::class);
        $payload = $this->validChatMessage();
        unset($payload['content']);
        $payload['prompt'] = 'x';
        $payload['schema'] = [];
        $this->validator->validate(MessageType::StructuredOutputRequest, $payload);
    }

    public function test_accepts_structured_request_with_schema(): void
    {
        $payload = $this->validChatMessage();
        unset($payload['content']);
        $payload['prompt'] = 'extract';
        $payload['schema'] = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];
        $this->validator->validate(MessageType::StructuredOutputRequest, $payload);
        $this->addToAssertionCount(1);
    }

    public function test_ack_requires_ack_for_and_status(): void
    {
        $this->expectException(InvalidProtocolMessageException::class);
        $payload = $this->validChatMessage();
        $this->validator->validate(MessageType::ChatAcknowledgement, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function validChatMessage(): array
    {
        return [
            'msg_id' => (string) Str::uuid7(),
            'session_id' => (string) Str::uuid7(),
            'from' => 'external:agent',
            'to' => 'fleetq:agent',
            'content' => 'hello',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
