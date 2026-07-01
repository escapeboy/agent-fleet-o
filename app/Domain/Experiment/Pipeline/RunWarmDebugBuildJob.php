<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Actions\ExecuteWarmDebugBuildAction;
use App\Domain\Experiment\Models\Experiment;
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

        $action->execute($experiment);
    }
}
