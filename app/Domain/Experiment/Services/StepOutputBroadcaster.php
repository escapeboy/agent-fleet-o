<?php

namespace App\Domain\Experiment\Services;

use Illuminate\Support\Facades\Redis;

class StepOutputBroadcaster
{
    private const KEY_PREFIX = 'step_stream:';

    private const TTL = 3600; // 1 hour

    public function __construct(private readonly OutputTruncator $truncator) {}

    /**
     * Append a chunk of streaming output for a step.
     */
    public function broadcastChunk(string $stepId, string $chunk): void
    {
        $key = self::KEY_PREFIX.$stepId;
        Redis::append($key, $chunk);
        Redis::expire($key, self::TTL);
    }

    /**
     * Get the accumulated streaming output for a step, truncated for display (≤32KB).
     */
    public function getAccumulatedOutput(string $stepId): ?string
    {
        $raw = Redis::get(self::KEY_PREFIX.$stepId);

        if ($raw === null) {
            return null;
        }

        return $this->truncator->truncate($raw);
    }

    /**
     * Get tightly truncated output (≤8KB) suitable for passing between pipeline stages.
     */
    public function getContextOutput(string $stepId): ?string
    {
        $raw = Redis::get(self::KEY_PREFIX.$stepId);

        if ($raw === null) {
            return null;
        }

        return $this->truncator->truncateForContext($raw);
    }

    /**
     * Clear the streaming output for a step (after it completes).
     */
    public function clear(string $stepId): void
    {
        Redis::del(self::KEY_PREFIX.$stepId);
    }
}
