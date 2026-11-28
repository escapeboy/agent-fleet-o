<?php

namespace App\Infrastructure\AI\Services;

/**
 * Mutable per-job holder for a crew task's Claude Code session, keyed by
 * agent id. Bound with app()->scoped() so it is flushed between queue jobs.
 *
 * ExecuteCrewTaskJob sets the session stored on the task (if any) before the
 * agent runs. LocalAgentGateway reads it to resume that session on a retry
 * and records the session id of the run it just made, so the job can store it.
 *
 * A stored session is resumed only when its hash (system prompt, working
 * directory, model) matches the current run; see hash().
 */
class ClaudeSessionContext
{
    public ?string $agentId = null;

    public ?string $storedSessionId = null;

    public ?string $storedHash = null;

    public ?string $resultSessionId = null;

    public ?string $resultHash = null;

    /**
     * Run $fn with the stored session set for $agentId and restore the
     * previous values afterwards. The result fields start empty.
     */
    public function with(string $agentId, ?string $storedSessionId, ?string $storedHash, callable $fn): mixed
    {
        $previous = [
            $this->agentId,
            $this->storedSessionId,
            $this->storedHash,
            $this->resultSessionId,
            $this->resultHash,
        ];

        $this->agentId = $agentId;
        $this->storedSessionId = $storedSessionId !== '' ? $storedSessionId : null;
        $this->storedHash = $storedHash !== '' ? $storedHash : null;
        $this->resultSessionId = null;
        $this->resultHash = null;

        try {
            return $fn();
        } finally {
            [
                $this->agentId,
                $this->storedSessionId,
                $this->storedHash,
                $this->resultSessionId,
                $this->resultHash,
            ] = $previous;
        }
    }

    public function activeFor(?string $agentId): bool
    {
        return $agentId !== null && $this->agentId !== null && $agentId === $this->agentId;
    }

    public function record(string $sessionId, string $hash): void
    {
        $this->resultSessionId = $sessionId;
        $this->resultHash = $hash;
    }

    /**
     * Fingerprint of what a Claude Code session was started with. A stored
     * session is only resumed when this is unchanged.
     */
    public static function hash(string $systemPrompt, string $workdir, ?string $model): string
    {
        return hash('sha256', (string) json_encode([$systemPrompt, $workdir, $model], JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
