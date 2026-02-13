<?php

namespace App\Domain\Experiment\Services;

use Illuminate\Support\Facades\Redis;

class StepOutputBroadcaster
{
    private const KEY_PREFIX = 'step_stream:';

    private const TTL = 3600; // 1 hour

    /**
     * Append a chunk of streaming output for a step.
     */
    public function broadcastChunk(string $stepId, string $chunk): void
    {
        $key = self::KEY_PREFIX . $stepId;
        Redis::append($key, $chunk);
        Redis::expire($key, self::TTL);
    }

    /**
     * Get the accumulated streaming output for a step.
     */
    public function getAccumulatedOutput(string $stepId): ?string
    {
        return Redis::get(self::KEY_PREFIX . $stepId);
    }

    /**
     * Clear the streaming output for a step (after it completes).
     */
    public function clear(string $stepId): void
    {
        Redis::del(self::KEY_PREFIX . $stepId);
    }
}
