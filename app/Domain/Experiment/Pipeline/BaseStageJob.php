<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Jobs\Middleware\CheckBudgetAvailable;
use App\Jobs\Middleware\CheckKillSwitch;
use App\Jobs\Middleware\TenantRateLimit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

abstract class BaseStageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public readonly string $experimentId,
        public readonly ?string $teamId = null,
    ) {
        $this->onQueue('experiments');
    }

    abstract protected function expectedState(): ExperimentStatus;

    abstract protected function stageType(): StageType;

    abstract protected function process(Experiment $experiment, ExperimentStage $stage): void;

    public function middleware(): array
    {
        return [
            new CheckKillSwitch(),
            new CheckBudgetAvailable(),
            new TenantRateLimit('experiments', 30),
            (new WithoutOverlapping($this->experimentId))->releaseAfter(300),
        ];
    }

    public function handle(): void
    {
        // Queue context: no auth user, so bypass global scope
        $experiment = Experiment::withoutGlobalScopes()->find($this->experimentId);

        if (!$experiment) {
            Log::warning('BaseStageJob: Experiment not found', [
                'experiment_id' => $this->experimentId,
                'job' => static::class,
            ]);
            return;
        }

        // State guard: skip if experiment is not in expected state
        if ($experiment->status !== $this->expectedState()) {
            Log::info('BaseStageJob: State guard — experiment not in expected state', [
                'experiment_id' => $experiment->id,
                'expected' => $this->expectedState()->value,
                'actual' => $experiment->status->value,
                'job' => class_basename(static::class),
            ]);
            return;
        }

        $stage = $this->findOrCreateStage($experiment);

        $stage->update([
            'status' => StageStatus::Running,
            'started_at' => now(),
        ]);

        $startTime = hrtime(true);

        try {
            $this->process($experiment, $stage);

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $stage->update([
                'status' => StageStatus::Completed,
                'duration_ms' => $durationMs,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $stage->update([
                'status' => StageStatus::Failed,
                'duration_ms' => $durationMs,
                'retry_count' => $stage->retry_count + 1,
                'output_snapshot' => array_merge($stage->output_snapshot ?? [], [
                    'error' => $e->getMessage(),
                ]),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('BaseStageJob: Stage failed after all retries', [
            'experiment_id' => $this->experimentId,
            'stage' => $this->stageType()->value,
            'error' => $exception->getMessage(),
            'job' => class_basename(static::class),
        ]);

        $experiment = Experiment::withoutGlobalScopes()->find($this->experimentId);
        if (!$experiment || $experiment->status->isTerminal()) {
            return;
        }

        // Transition to the corresponding failed state
        $failedState = $this->failedState();
        if ($failedState) {
            try {
                app(TransitionExperimentAction::class)->execute(
                    experiment: $experiment,
                    toState: $failedState,
                    reason: "Stage failed: {$exception->getMessage()}",
                );
            } catch (\Throwable $e) {
                Log::error('BaseStageJob: Failed to transition to failed state', [
                    'experiment_id' => $this->experimentId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function failedState(): ?ExperimentStatus
    {
        return match ($this->stageType()) {
            StageType::Scoring => ExperimentStatus::ScoringFailed,
            StageType::Planning => ExperimentStatus::PlanningFailed,
            StageType::Building => ExperimentStatus::BuildingFailed,
            StageType::Executing => ExperimentStatus::ExecutionFailed,
            default => null,
        };
    }

    protected function findOrCreateStage(Experiment $experiment): ExperimentStage
    {
        return ExperimentStage::withoutGlobalScopes()->firstOrCreate(
            [
                'experiment_id' => $experiment->id,
                'stage' => $this->stageType(),
                'iteration' => $experiment->current_iteration,
            ],
            [
                'team_id' => $experiment->team_id,
                'status' => StageStatus::Pending,
                'retry_count' => 0,
            ],
        );
    }

    protected function generateIdempotencyKey(string $suffix = ''): string
    {
        return hash('xxh128', implode('|', [
            $this->experimentId,
            $this->stageType()->value,
            $suffix,
        ]));
    }
}
