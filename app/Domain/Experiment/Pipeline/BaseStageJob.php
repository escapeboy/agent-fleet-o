<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Shared\Models\Team;
use App\Jobs\Middleware\CheckBudgetAvailable;
use App\Jobs\Middleware\CheckKillSwitch;
use App\Jobs\Middleware\TenantRateLimit;
use App\Models\GlobalSetting;
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
    public int $backoff = 60;

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
        Log::info('BaseStageJob: Starting', [
            'experiment_id' => $this->experimentId,
            'stage' => $this->stageType()->value,
            'expected_state' => $this->expectedState()->value,
            'attempt' => $this->attempts(),
            'job' => class_basename(static::class),
            'queue' => $this->queue,
        ]);

        // Queue context: no auth user, so bypass global scope
        $experiment = Experiment::withoutGlobalScopes()->find($this->experimentId);

        if (! $experiment) {
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

            // Only update if not already completed by process() — some stages
            // (e.g. RunPlanningStage) mark themselves Completed before calling
            // transitions to satisfy prerequisite validators.
            $freshStage = $stage->fresh();
            if ($freshStage->status !== StageStatus::Completed) {
                $stage->update([
                    'status' => StageStatus::Completed,
                    'duration_ms' => $durationMs,
                    'completed_at' => now(),
                ]);
            } elseif (! $freshStage->duration_ms) {
                $stage->update(['duration_ms' => $durationMs]);
            }

            Log::info('BaseStageJob: Completed', [
                'experiment_id' => $this->experimentId,
                'stage' => $this->stageType()->value,
                'duration_ms' => $durationMs,
            ]);
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            Log::error('BaseStageJob: Exception in process()', [
                'experiment_id' => $this->experimentId,
                'stage' => $this->stageType()->value,
                'attempt' => $this->attempts(),
                'duration_ms' => $durationMs,
                'exception' => $e->getMessage(),
                'class' => get_class($e),
            ]);

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
                    reason: substr("Stage failed: {$exception->getMessage()}", 0, 250),
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

    /**
     * Parse a JSON response from the LLM, handling markdown code fences
     * and the {'text': '...'} wrapper from idempotency cache.
     */
    protected function parseJsonResponse(\App\Infrastructure\AI\DTOs\AiResponseDTO $response): ?array
    {
        // If parsedOutput is already a proper result (not a text wrapper), use it
        if (is_array($response->parsedOutput) && !isset($response->parsedOutput['text'])) {
            $parsed = $response->parsedOutput;

            // Handle Claude Code / local agent output: {type: "result", result: "{...json...}"}
            if (isset($parsed['type'], $parsed['result']) && $parsed['type'] === 'result' && is_string($parsed['result'])) {
                $inner = $this->stripMarkdownCodeFences($parsed['result']);
                $decoded = json_decode($inner, true);

                return is_array($decoded) ? $decoded : $parsed;
            }

            return $parsed;
        }

        // Get the raw text — either from parsedOutput['text'] or content
        $raw = $response->parsedOutput['text'] ?? $response->content;

        // Strip markdown code fences
        $raw = $this->stripMarkdownCodeFences($raw);

        return json_decode($raw, true);
    }

    protected function stripMarkdownCodeFences(string $content): string
    {
        if (preg_match('/```(?:json)?\s*\n?(.*?)```/s', $content, $matches)) {
            return trim($matches[1]);
        }

        return $content;
    }

    /**
     * Resolve the LLM provider/model for pipeline stages.
     * Priority: experiment config → team settings → platform default.
     *
     * @return array{provider: string, model: string}
     */
    protected function resolvePipelineLlm(Experiment $experiment): array
    {
        // 1. Experiment-level override (config.llm or constraints.llm)
        $config = $experiment->config ?? [];
        if (! empty($config['llm']['provider']) && ! empty($config['llm']['model'])) {
            return [
                'provider' => $config['llm']['provider'],
                'model' => $config['llm']['model'],
            ];
        }

        $constraints = $experiment->constraints ?? [];
        if (! empty($constraints['llm']['provider']) && ! empty($constraints['llm']['model'])) {
            return [
                'provider' => $constraints['llm']['provider'],
                'model' => $constraints['llm']['model'],
            ];
        }

        // 2. Team-level default
        $team = Team::withoutGlobalScopes()->find($experiment->team_id);
        $settings = $team?->settings ?? [];
        if (! empty($settings['default_llm_provider']) && ! empty($settings['default_llm_model'])) {
            return [
                'provider' => $settings['default_llm_provider'],
                'model' => $settings['default_llm_model'],
            ];
        }

        // 3. Platform default (GlobalSetting → config fallback)
        $platformProvider = GlobalSetting::get('default_llm_provider') ?? config('llm_pricing.default_provider', 'anthropic');
        $platformModel = GlobalSetting::get('default_llm_model') ?? config('llm_pricing.default_model', 'claude-sonnet-4-5');

        return [
            'provider' => $platformProvider,
            'model' => $platformModel,
        ];
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
