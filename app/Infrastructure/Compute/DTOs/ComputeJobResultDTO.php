<?php

namespace App\Infrastructure\Compute\DTOs;

/**
 * Normalized result from any compute provider.
 *
 * Status vocabulary is canonical regardless of what the underlying provider returns:
 *   'queued'    — job is in the provider's queue
 *   'running'   — job is actively executing
 *   'completed' — job finished successfully
 *   'failed'    — job finished with an error
 *   'cancelled' — job was cancelled or timed out
 */
readonly class ComputeJobResultDTO
{
    public function __construct(
        public string $status,
        public array $output = [],
        public ?string $jobId = null,
        public ?string $error = null,
        public int $durationMs = 0,
    ) {}

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled'], true);
    }
}
