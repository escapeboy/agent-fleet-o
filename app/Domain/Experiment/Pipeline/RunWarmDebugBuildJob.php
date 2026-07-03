<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Actions\ExecuteWarmDebugBuildAction;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Infrastructure\AI\Exceptions\VpsLocalAgentException;
use App\Jobs\Middleware\CheckKillSwitch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs the platform-side warm-build debug builder in its own long-lived job so
 * the RunBuildingStage job can return immediately (stage stays Running). A build
 * is a single agentic coding session — never retried (a retry would re-run the
 * agent and re-spend), so failures go straight to BuildingFailed via the action.
 */
class RunWarmDebugBuildJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 2000;

    public function __construct(
        public readonly string $experimentId,
        public readonly ?string $teamId = null,
    ) {
        $this->onQueue('ai-calls');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new CheckKillSwitch];
    }

    public function handle(ExecuteWarmDebugBuildAction $action): void
    {
        $experiment = Experiment::withoutGlobalScopes()->find($this->experimentId);
        if (! $experiment) {
            Log::warning('RunWarmDebugBuildJob: experiment not found', ['experiment_id' => $this->experimentId]);

            return;
        }

        try {
            $action->execute($experiment);
        } catch (VpsLocalAgentException $e) {
            if (! $e->retryable) {
                throw $e;
            }

            // Transient VPS concurrency cap: nothing was spent (the slot is
            // acquired before any agent work). Wait for a slot by re-dispatching
            // after a backoff, up to a per-stage budget, then give up cleanly.
            $this->reDispatchOrFail($experiment, $action);
        }
    }

    private function reDispatchOrFail(Experiment $experiment, ExecuteWarmDebugBuildAction $action): void
    {
        $stage = ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('stage', StageType::Building)
            ->where('status', StageStatus::Running)
            ->latest()
            ->first();

        $attempts = (int) ($stage?->output_snapshot['_transient_retries'] ?? 0);
        $max = (int) config('experiments.transient_capacity.max_retries', 20);

        if ($attempts >= $max) {
            $action->failCapacityExhausted($experiment);

            return;
        }

        $stage?->update([
            'output_snapshot' => array_merge($stage->output_snapshot ?? [], [
                '_transient_retries' => $attempts + 1,
            ]),
        ]);

        $backoff = (int) config('experiments.transient_capacity.backoff_seconds', 60);

        Log::info('RunWarmDebugBuildJob: transient capacity limit — re-dispatching after backoff', [
            'experiment_id' => $experiment->id,
            'transient_retry' => $attempts + 1,
            'max_retries' => $max,
            'backoff_seconds' => $backoff,
        ]);

        self::dispatch($this->experimentId, $this->teamId)->delay(now()->addSeconds($backoff));
    }
}
