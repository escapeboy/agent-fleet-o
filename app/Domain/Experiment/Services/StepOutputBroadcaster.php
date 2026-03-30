<?php

namespace App\Domain\Experiment\Services;

use App\Domain\Experiment\Models\PlaybookStep;
use App\Events\WorkflowNodeUpdated;
use Illuminate\Support\Facades\Log;
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

    /**
     * Broadcast a workflow node status update via Reverb.
     *
     * @param  array{duration_ms?:int, token_count?:int, cost?:float, output_preview?:string}  $metrics
     */
    public function broadcastNodeStatus(PlaybookStep $step, string $status, array $metrics = []): void
    {
        if (! $step->workflow_node_id) {
            return;
        }

        try {
            event(new WorkflowNodeUpdated(
                experimentId: $step->experiment_id,
                nodeId: $step->workflow_node_id,
                status: $status,
                durationMs: $metrics['duration_ms'] ?? 0,
                tokenCount: $metrics['token_count'] ?? 0,
                cost: $metrics['cost'] ?? 0.0,
                outputPreview: $metrics['output_preview'] ?? '',
            ));
        } catch (\Throwable $e) {
            // Broadcast failures must never block execution
            Log::warning('StepOutputBroadcaster: failed to broadcast node status', [
                'step_id' => $step->id,
                'node_id' => $step->workflow_node_id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
