<?php

namespace App\Infrastructure\AI\DTOs;

/**
 * Result of parsing a local-agent session transcript into normalized turns.
 *
 * Aggregate token/tool counts are derived from the turns so the ingestor can
 * stamp them on the session root span without re-walking the list.
 */
class ParsedTranscript
{
    /**
     * @param  list<TranscriptTurn>  $turns
     */
    public function __construct(
        public readonly ?string $sessionId,
        public readonly array $turns,
    ) {}

    public function turnCount(): int
    {
        return count($this->turns);
    }

    public function totalPromptTokens(): int
    {
        return array_sum(array_map(fn (TranscriptTurn $t): int => $t->promptTokens, $this->turns));
    }

    public function totalCompletionTokens(): int
    {
        return array_sum(array_map(fn (TranscriptTurn $t): int => $t->completionTokens, $this->turns));
    }

    public function toolCallCount(): int
    {
        return array_sum(array_map(fn (TranscriptTurn $t): int => count($t->toolCalls), $this->turns));
    }
}
