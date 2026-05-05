<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\DTOs;

use Illuminate\Support\Str;

final readonly class StructuredResponseDTO
{
    public function __construct(
        public string $msgId,
        public string $inReplyTo,
        public string $sessionId,
        public string $from,
        public string $to,
        public ?array $output,
        public string $timestamp,
        public bool $error = false,
        public ?string $errorMessage = null,
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            msgId: (string) ($data['msg_id'] ?? Str::uuid7()->toString()),
            inReplyTo: (string) $data['in_reply_to'],
            sessionId: (string) $data['session_id'],
            from: (string) $data['from'],
            to: (string) $data['to'],
            output: isset($data['output']) ? (array) $data['output'] : null,
            timestamp: (string) ($data['timestamp'] ?? now()->toIso8601String()),
            error: (bool) ($data['error'] ?? false),
            errorMessage: isset($data['error_message']) ? (string) $data['error_message'] : null,
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
            'in_reply_to' => $this->inReplyTo,
            'session_id' => $this->sessionId,
            'from' => $this->from,
            'to' => $this->to,
            'output' => $this->output,
            'timestamp' => $this->timestamp,
            'error' => $this->error,
            'error_message' => $this->errorMessage,
            'metadata' => $this->metadata,
        ], fn ($v) => $v !== null && $v !== [] && $v !== false);
    }
}
