<?php

namespace App\Infrastructure\AI\DTOs;

/**
 * One normalized turn extracted from a local-agent session transcript.
 *
 * `toolCalls` is a list of [name, input] pairs for each tool the assistant
 * invoked in this turn. `text` is the concatenated text content (used for the
 * span body, redacted when masking is on).
 */
class TranscriptTurn
{
    /**
     * @param  'user'|'assistant'  $role
     * @param  list<array{name: string, input: array<string, mixed>}>  $toolCalls
     */
    public function __construct(
        public readonly int $index,
        public readonly string $role,
        public readonly ?string $model,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly array $toolCalls,
        public readonly ?string $text,
        public readonly int $timestampNanos,
    ) {}

    public function isAssistant(): bool
    {
        return $this->role === 'assistant';
    }
}
