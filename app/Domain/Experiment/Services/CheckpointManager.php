<?php

namespace App\Domain\Experiment\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckpointManager
{
    private const HEARTBEAT_INTERVAL = 30; // seconds

    private const IDEMPOTENCY_TTL = 604800; // 7 days in seconds

    /**
     * Write a checkpoint for a step. Uses direct DB to minimize overhead.
     */
    public function writeCheckpoint(string $stepId, array $data, ?string $workerId = null): void
    {
        DB::table('playbook_steps')
            ->where('id', $stepId)
            ->update([
                'checkpoint_data' => json_encode($data),
                'last_heartbeat_at' => now(),
                'worker_id' => $workerId ?? $this->resolveWorkerId(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Read checkpoint data for a step.
     */
    public function readCheckpoint(string $stepId): ?array
    {
        $row = DB::table('playbook_steps')
            ->where('id', $stepId)
            ->select('checkpoint_data')
            ->first();

        if (! $row || $row->checkpoint_data === null) {
            return null;
        }

        return json_decode($row->checkpoint_data, true);
    }

    /**
     * Update heartbeat timestamp. Lightweight — single column update.
     */
    public function heartbeat(string $stepId): void
    {
        DB::table('playbook_steps')
            ->where('id', $stepId)
            ->update([
                'last_heartbeat_at' => now(),
            ]);
    }

    /**
     * Clear checkpoint after step completes successfully.
     */
    public function clearCheckpoint(string $stepId): void
    {
        DB::table('playbook_steps')
            ->where('id', $stepId)
            ->update([
                'checkpoint_data' => null,
                'worker_id' => null,
            ]);
    }

    /**
     * Generate an idempotency key for a step execution.
     */
    public function generateIdempotencyKey(string $experimentId, string $stepId, int $loopCount = 0): string
    {
        return hash('xxh128', "{$experimentId}:{$stepId}:{$loopCount}");
    }

    /**
     * Check if a result exists for an idempotency key (cached in Redis).
     */
    public function getIdempotentResult(string $key): ?array
    {
        $cached = Cache::store('redis')->get("idempotency:{$key}");

        return $cached ? json_decode($cached, true) : null;
    }

    /**
     * Store a result for an idempotency key (cached in Redis with TTL).
     */
    public function storeIdempotentResult(string $key, array $result): void
    {
        Cache::store('redis')->put(
            "idempotency:{$key}",
            json_encode($result),
            self::IDEMPOTENCY_TTL,
        );
    }

    /**
     * Start heartbeat using pcntl_alarm if available.
     * Returns a cleanup callable to stop the heartbeat.
     */
    public function startHeartbeat(string $stepId): ?callable
    {
        if (! function_exists('pcntl_alarm') || ! function_exists('pcntl_signal')) {
            return null;
        }

        $manager = $this;

        pcntl_signal(SIGALRM, function () use ($stepId, $manager) {
            try {
                $manager->heartbeat($stepId);
            } catch (\Throwable $e) {
                Log::debug('CheckpointManager: Heartbeat failed', [
                    'step_id' => $stepId,
                    'error' => $e->getMessage(),
                ]);
            }
            pcntl_alarm(self::HEARTBEAT_INTERVAL);
        });

        pcntl_alarm(self::HEARTBEAT_INTERVAL);

        return function () {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
        };
    }

    /**
     * Cleanup old checkpoint data from completed steps.
     */
    public function cleanupOldCheckpoints(int $retentionDays = 7): int
    {
        $cutoff = now()->subDays($retentionDays);

        return DB::table('playbook_steps')
            ->whereNotNull('checkpoint_data')
            ->where('status', 'completed')
            ->where('completed_at', '<', $cutoff)
            ->update([
                'checkpoint_data' => null,
                'worker_id' => null,
            ]);
    }

    /**
     * Resolve a unique worker ID for the current process.
     */
    private function resolveWorkerId(): string
    {
        return gethostname().'.'.getmypid();
    }
}
