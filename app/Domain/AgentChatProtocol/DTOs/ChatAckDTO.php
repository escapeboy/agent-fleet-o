<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\DTOs;

use App\Domain\AgentChatProtocol\Enums\AckStatus;
use Illuminate\Support\Str;

final readonly class ChatAckDTO
{
    public function __construct(
        public string $msgId,
        public string $ackFor,
        public string $sessionId,
        public AckStatus $status,
        public string $timestamp,
        public ?string $error = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            msgId: (string) ($data['msg_id'] ?? Str::uuid7()->toString()),
            ackFor: (string) $data['ack_for'],
            sessionId: (string) $data['session_id'],
            status: AckStatus::from((string) $data['status']),
            timestamp: (string) ($data['timestamp'] ?? now()->toIso8601String()),
            error: isset($data['error']) ? (string) $data['error'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'msg_id' => $this->msgId,
            'ack_for' => $this->ackFor,
            'session_id' => $this->sessionId,
            'status' => $this->status->value,
            'timestamp' => $this->timestamp,
            'error' => $this->error,
        ], fn ($v) => $v !== null);
    }
}
