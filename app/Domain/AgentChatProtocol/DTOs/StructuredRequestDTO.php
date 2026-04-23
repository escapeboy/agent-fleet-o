<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\DTOs;

use Illuminate\Support\Str;

final readonly class StructuredRequestDTO
{
    public function __construct(
        public string $msgId,
        public string $sessionId,
        public string $from,
        public string $to,
        public string $prompt,
        public array $schema,
        public string $timestamp,
        public ?string $inReplyTo = null,
        public ?string $responseUrl = null,
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            msgId: (string) ($data['msg_id'] ?? Str::uuid7()->toString()),
            sessionId: (string) $data['session_id'],
            from: (string) $data['from'],
            to: (string) $data['to'],
            prompt: (string) ($data['prompt'] ?? ''),
            schema: (array) ($data['schema'] ?? []),
            timestamp: (string) ($data['timestamp'] ?? now()->toIso8601String()),
            inReplyTo: isset($data['in_reply_to']) ? (string) $data['in_reply_to'] : null,
            responseUrl: isset($data['response_url']) ? (string) $data['response_url'] : null,
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'msg_id' => $this->msgId,
            'session_id' => $this->sessionId,
            'from' => $this->from,
            'to' => $this->to,
            'prompt' => $this->prompt,
            'schema' => $this->schema,
            'timestamp' => $this->timestamp,
            'in_reply_to' => $this->inReplyTo,
            'response_url' => $this->responseUrl,
            'metadata' => $this->metadata,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
