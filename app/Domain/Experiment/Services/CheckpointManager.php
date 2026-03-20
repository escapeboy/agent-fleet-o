<?php

namespace App\Domain\Experiment\Services;

use App\Domain\Experiment\Enums\CheckpointMode;
use App\Domain\Experiment\Jobs\FlushCheckpointJob;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckpointManager
{
    private const HEARTBEAT_INTERVAL = 30; // seconds

    private const IDEMPOTENCY_TTL = 604800; // 7 days in seconds

    private const ASYNC_BUFFER_TTL = 3600; // 1 hour

    /** In-memory buffer for exit mode — flushed on clearCheckpoint() or flushPendingCheckpoints(). */
    private array $pendingCheckpoints = [];

    /**
     * Resolve the cache store for checkpoint buffers.
     * Uses Redis in production, falls back to the default store in testing.
     */
    private function cacheStore(): Repository
    {
        try {
            return Cache::store('redis');
        } catch (\Throwable) {
            return Cache::store();
        }
    }

    /**
     * Write a checkpoint for a step. Behaviour depends on the checkpoint mode:
     *   - sync:  Direct DB write (safest, current default)
     *   - async: Buffer in Redis then dispatch FlushCheckpointJob (fast, small loss window)
     *   - exit:  Buffer in memory, flushed on step completion/failure (fastest, larger loss window)
     */
    public function writeCheckpoint(string $stepId, array $data, ?string $workerId = null, CheckpointMode $mode = CheckpointMode::Sync): void
    {
        $workerId = $workerId ?? $this->resolveWorkerId();

        match ($mode) {
            CheckpointMode::Sync => $this->writeSyncCheckpoint($stepId, $data, $workerId),
            CheckpointMode::Async => $this->writeAsyncCheckpoint($stepId, $data, $workerId),
            CheckpointMode::Exit => $this->bufferCheckpoint($stepId, $data, $workerId),
        };
    }

    /**
     * Read checkpoint data for a step.
     */
    public function readCheckpoint(string $stepId): ?array
    {
        // Check in-memory buffer first (exit mode)
        if (isset($this->pendingCheckpoints[$stepId])) {
            return $this->pendingCheckpoints[$stepId]['checkpoint_data'];
        }

        // Check Redis buffer (async mode that hasn't flushed yet)
        $redisKey = "checkpoint_buffer:{$stepId}";
        $cached = $this->cacheStore()->get($redisKey);

        if ($cached) {
            $decoded = json_decode($cached, true);

            return $decoded['checkpoint_data'] ?? null;
        }

        // Fall through to DB
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
     * Also flushes any pending in-memory checkpoint (exit mode) to DB before clearing.
     */
    public function clearCheckpoint(string $stepId): void
    {
        // Flush exit-mode buffer to DB first (so recovery can find it if clear fails)
        if (isset($this->pendingCheckpoints[$stepId])) {
            $pending = $this->pendingCheckpoints[$stepId];
            $this->writeSyncCheckpoint($stepId, $pending['checkpoint_data'], $pending['worker_id']);
            unset($this->pendingCheckpoints[$stepId]);
        }

        // Remove any async Redis buffer (non-critical, ignore if Redis unavailable)
        try {
            $this->cacheStore()->forget("checkpoint_buffer:{$stepId}");
        } catch (\Throwable) {
            // Redis may not be available in test/local environments
        }

        DB::table('playbook_steps')
            ->where('id', $stepId)
            ->update([
                'checkpoint_data' => null,
                'worker_id' => null,
            ]);
    }

    /**
     * Flush all in-memory pending checkpoints to DB.
     * Called from failed() callbacks to persist state before job dies.
     */
    public function flushPendingCheckpoints(): void
    {
        foreach ($this->pendingCheckpoints as $stepId => $data) {
            try {
                $this->writeSyncCheckpoint($stepId, $data['checkpoint_data'], $data['worker_id']);
            } catch (\Throwable $e) {
                Log::warning('CheckpointManager: failed to flush pending checkpoint', [
                    'step_id' => $stepId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->pendingCheckpoints = [];
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
        $cached = $this->cacheStore()->get("idempotency:{$key}");

        return $cached ? json_decode($cached, true) : null;
    }

    /**
     * Store a result for an idempotency key (cached in Redis with TTL).
     */
    public function storeIdempotentResult(string $key, array $result): void
    {
        $this->cacheStore()->put(
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
     * Synchronous DB write (safest mode).
     */
    private function writeSyncCheckpoint(string $stepId, array $data, string $workerId): void
    {
        DB::table('playbook_steps')
            ->where('id', $stepId)
            ->update([
                'checkpoint_data' => json_encode($data),
                'last_heartbeat_at' => now(),
                'worker_id' => $workerId,
                'updated_at' => now(),
            ]);
    }

    /**
     * Async mode: write to Redis buffer, dispatch job to flush to DB.
     */
    private function writeAsyncCheckpoint(string $stepId, array $data, string $workerId): void
    {
        $redisKey = "checkpoint_buffer:{$stepId}";

        $this->cacheStore()->put($redisKey, json_encode([
            'checkpoint_data' => $data,
            'worker_id' => $workerId,
        ]), self::ASYNC_BUFFER_TTL);

        FlushCheckpointJob::dispatch($stepId);
    }

    /**
     * Exit mode: buffer in memory only. Flushed on clearCheckpoint() or flushPendingCheckpoints().
     */
    private function bufferCheckpoint(string $stepId, array $data, string $workerId): void
    {
        $this->pendingCheckpoints[$stepId] = [
            'checkpoint_data' => $data,
            'worker_id' => $workerId,
        ];
    }

    /**
     * Resolve a unique worker ID for the current process.
     */
    private function resolveWorkerId(): string
    {
        return gethostname().'.'.getmypid();
    }
}
