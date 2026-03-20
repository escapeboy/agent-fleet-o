<?php

namespace App\Domain\Experiment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FlushCheckpointJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public readonly string $stepId,
    ) {
        $this->onQueue('metrics');
    }

    public function handle(): void
    {
        $redisKey = "checkpoint_buffer:{$this->stepId}";
        $cached = Cache::store('redis')->get($redisKey);

        if (! $cached) {
            return;
        }

        $data = json_decode($cached, true);

        if (! is_array($data)) {
            return;
        }

        DB::table('playbook_steps')
            ->where('id', $this->stepId)
            ->update([
                'checkpoint_data' => json_encode($data['checkpoint_data'] ?? []),
                'last_heartbeat_at' => now(),
                'worker_id' => $data['worker_id'] ?? null,
                'updated_at' => now(),
            ]);

        Cache::store('redis')->forget($redisKey);

        Log::debug('FlushCheckpointJob: flushed async checkpoint', [
            'step_id' => $this->stepId,
        ]);
    }
}
