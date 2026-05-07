<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Events\ExperimentContextApproachingLimit;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Pipeline\Contracts\StageVerifier;
use App\Domain\Experiment\Pipeline\Verification\BuildingVerifier;
use App\Domain\Experiment\Pipeline\Verification\EvaluatingVerifier;
use App\Domain\Experiment\Pipeline\Verification\ExecutingVerifier;
use App\Domain\Experiment\Pipeline\Verification\MetricsVerifier;
use App\Domain\Experiment\Pipeline\Verification\PlanningVerifier;
use App\Domain\Experiment\Pipeline\Verification\ScoringVerifier;
use App\Domain\Experiment\Services\CheckpointManager;
use App\Domain\Experiment\Services\PipelineContextCompressor;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Models\LlmRequestLog;
use App\Infrastructure\AI\Services\ContextHealthService;
use App\Jobs\Middleware\ApplyTenantTracer;
use App\Jobs\Middleware\CheckBudgetAvailable;
use App\Jobs\Middleware\CheckKillSwitch;
use App\Jobs\Middleware\CheckKmsAvailable;
use App\Jobs\Middleware\EnforceConcurrencyLimit;
use App\Jobs\Middleware\EnforceExecutionDepth;
use App\Jobs\Middleware\EnforceExecutionTtl;
use App\Jobs\Middleware\EnforceTenantContext;
use App\Jobs\Middleware\TenantRateLimit;
use App\Models\GlobalSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    /**
     * Get compressed preceding stage context for use in LLM requests.
     * Compresses when preceding context exceeds the configured token threshold.
     * Individual stage jobs should call this instead of manually gathering stage outputs.
     */
    protected function getPrecedingContext(Experiment $experiment, ExperimentStage $currentStage): string
    {
        $compressor = app(PipelineContextCompressor::class);

        if ($compressor->shouldCompress($experiment, $currentStage)) {
            return $compressor->compress($experiment, $currentStage);
        }

        // No compression needed — return raw stage outputs
        $stages = $experiment->stages()
            ->where('status', StageStatus::Completed->value)
            ->where('started_at', '<', $currentStage->started_at ?? now())
            ->orderBy('started_at')
            ->get();

        return $stages->map(fn ($s) => '**['.
            ($s->stage instanceof \BackedEnum ? $s->stage->value : $s->stage).
            "]**\n".json_encode($s->output_snapshot),
        )->implode("\n\n");
    }

    public function middleware(): array
    {
        return [
            new EnforceTenantContext,
            new ApplyTenantTracer,
            new CheckKillSwitch,
            new CheckBudgetAvailable,
            new CheckKmsAvailable,
            new EnforceExecutionTtl,
            new EnforceExecutionDepth,
            new EnforceConcurrencyLimit,
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

        // Record the model tier for this stage in the output snapshot for observability
        $modelTier = config("experiments.stage_model_tiers.{$this->stageType()->value}") ?? 'standard';

        $stage->update([
            'status' => StageStatus::Running,
            'started_at' => now(),
            'output_snapshot' => array_merge($stage->output_snapshot ?? [], [
                'model_tier' => $modelTier,
            ]),
        ]);

        $startTime = hrtime(true);

        try {
            $verificationEnabled = GlobalSetting::get('ai_routing.verification_enabled') ?? config('ai_routing.verification.enabled', true);
            $maxVerificationRetries = (int) (GlobalSetting::get('ai_routing.verification_max_retries') ?? config('ai_routing.verification.max_retries', 2));
            $timeoutWarning = (int) config('ai_routing.verification.timeout_warning_seconds', 200);
            $verificationAttempt = 0;

            do {
                $this->process($experiment, $stage);

                // Run verification gate if enabled
                if ($verificationEnabled) {
                    $freshStage = $stage->fresh();
                    $verification = $this->verifyStageOutput($experiment, $freshStage);

                    if (! $verification['passed']) {
                        $verificationAttempt++;

                        if ($verificationAttempt <= $maxVerificationRetries) {
                            $elapsedSeconds = (int) ((hrtime(true) - $startTime) / 1_000_000_000);

                            Log::warning('BaseStageJob: Verification failed, retrying within job', [
                                'experiment_id' => $this->experimentId,
                                'stage' => $this->stageType()->value,
                                'attempt' => $verificationAttempt,
                                'max_retries' => $maxVerificationRetries,
                                'elapsed_seconds' => $elapsedSeconds,
                                'errors' => $verification['errors'],
                            ]);

                            if ($elapsedSeconds >= $timeoutWarning) {
                                Log::warning('BaseStageJob: Verification retry approaching timeout', [
                                    'experiment_id' => $this->experimentId,
                                    'stage' => $this->stageType()->value,
                                    'elapsed_seconds' => $elapsedSeconds,
                                    'timeout_warning' => $timeoutWarning,
                                ]);
                            }

                            // Inject verification errors into output so the next AI call can see them
                            $stage->update([
                                'retry_count' => $freshStage->retry_count + 1,
                                'output_snapshot' => array_merge($freshStage->output_snapshot ?? [], [
                                    '_verification_errors' => $verification['errors'],
                                    '_verification_attempt' => $verificationAttempt,
                                ]),
                            ]);

                            // Refresh stage for the next process() iteration
                            $stage = $stage->fresh();

                            continue;
                        }

                        // No retries left — log and let existing completion logic proceed
                        Log::error('BaseStageJob: Verification failed after all retries', [
                            'experiment_id' => $this->experimentId,
                            'stage' => $this->stageType()->value,
                            'attempts' => $verificationAttempt,
                            'errors' => $verification['errors'],
                        ]);
                    }
                }

                break;
            } while (true);

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            // Collect per-node telemetry from LLM request logs written during this stage
            $stageStarted = $stage->started_at ?? now()->subMilliseconds($durationMs);
            $tokenUsage = LlmRequestLog::where('experiment_id', $this->experimentId)
                ->where('created_at', '>=', $stageStarted)
                ->selectRaw('COALESCE(SUM((usage->>\'input_tokens\')::int), 0) as input_tokens, COALESCE(SUM((usage->>\'output_tokens\')::int), 0) as output_tokens, COUNT(*) as llm_calls')
                ->first();

            $telemetry = [
                'stage_latency_ms' => $durationMs,
                'retry_round' => $verificationAttempt,
                'job_attempts' => $this->attempts(),
                'token_input' => (int) ($tokenUsage->input_tokens ?? 0),
                'token_output' => (int) ($tokenUsage->output_tokens ?? 0),
                'llm_calls' => (int) ($tokenUsage->llm_calls ?? 0),
            ];

            // Only update if not already completed by process() — some stages
            // (e.g. RunPlanningStage) mark themselves Completed before calling
            // transitions to satisfy prerequisite validators.
            $freshStage = $stage->fresh();
            if ($freshStage->status !== StageStatus::Completed) {
                $stage->update([
                    'status' => StageStatus::Completed,
                    'duration_ms' => $durationMs,
                    'completed_at' => now(),
                    'telemetry' => array_merge($freshStage->telemetry ?? [], $telemetry),
                ]);
            } elseif (! $freshStage->duration_ms) {
                $stage->update([
                    'duration_ms' => $durationMs,
                    'telemetry' => array_merge($freshStage->telemetry ?? [], $telemetry),
                ]);
            }

            // Populate searchable_text for FTS after successful completion
            $this->populateSearchableText($experiment, $stage->fresh());

            Log::info('BaseStageJob: Completed', [
                'experiment_id' => $this->experimentId,
                'stage' => $this->stageType()->value,
                'duration_ms' => $durationMs,
                'verification_attempts' => $verificationAttempt,
            ]);

            $this->checkContextHealth($experiment);
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
        if (! $experiment || $experiment->status->isTerminal()) {
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
            // Post-execution stages: treat failure as completed — the experiment ran and
            // delivered its output; metrics/evaluation are best-effort secondary steps.
            StageType::CollectingMetrics, StageType::Evaluating => ExperimentStatus::Completed,
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
    protected function parseJsonResponse(AiResponseDTO $response): ?array
    {
        // If parsedOutput is already a proper result (not a text wrapper), use it
        if (is_array($response->parsedOutput) && ! isset($response->parsedOutput['text'])) {
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
        // Strip thinking tags
        $content = preg_replace('/<thinking>.*?<\/thinking>/s', '', $content);

        // Extract from markdown code fences
        if (preg_match('/```(?:json)?\s*\n?(.*?)```/s', $content, $matches)) {
            return trim($matches[1]);
        }

        // If content doesn't start with { or [, try to extract JSON from surrounding text
        $content = trim($content);
        if (! str_starts_with($content, '{') && ! str_starts_with($content, '[')) {
            if (preg_match('/(\{[\s\S]*\})\s*$/', $content, $matches)) {
                return $matches[1];
            }
        }

        return $content;
    }

    /**
     * Resolve the LLM provider/model for pipeline stages.
     * Priority: experiment config → team settings → stage model tier → platform default.
     *
     * Stage model tiers route cheap stages (scoring, collecting_metrics) to smaller
     * models and expensive stages (planning, building) to top-tier models, reducing
     * cost while preserving quality where it matters.
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
        $settings = $team->settings ?? [];
        if (! empty($settings['default_llm_provider']) && ! empty($settings['default_llm_model'])) {
            return [
                'provider' => $settings['default_llm_provider'],
                'model' => $settings['default_llm_model'],
            ];
        }

        // 3. Stage model tier routing — use cheaper/expensive models per stage
        $tierResolved = $this->resolveStageModelTier($team);
        if ($tierResolved) {
            return $tierResolved;
        }

        // 4. Platform default (GlobalSetting → config fallback)
        $platformProvider = GlobalSetting::get('default_llm_provider') ?? config('llm_pricing.default_provider', 'anthropic');
        $platformModel = GlobalSetting::get('default_llm_model') ?? config('llm_pricing.default_model', 'claude-sonnet-4-5');

        return [
            'provider' => $platformProvider,
            'model' => $platformModel,
        ];
    }

    /**
     * Resolve a tier-specific model for the current stage.
     *
     * Returns null when the stage maps to 'standard' tier (use default)
     * or when no configured provider is available for the team.
     *
     * @return array{provider: string, model: string}|null
     */
    protected function resolveStageModelTier(?Team $team): ?array
    {
        $stageKey = $this->stageType()->value;
        $teamTiers = $team?->settings['stage_model_tiers'] ?? null;
        $tier = (is_array($teamTiers) ? ($teamTiers[$stageKey] ?? null) : null)
            ?? config("experiments.stage_model_tiers.{$stageKey}");

        if (! $tier || $tier === 'standard') {
            return null;
        }

        $tierModels = config("experiments.model_tiers.{$tier}");
        if (! $tierModels || ! is_array($tierModels)) {
            return null;
        }

        // Pick the first provider the team (or platform) has credentials for
        foreach ($tierModels as $provider => $model) {
            if ($this->teamOrPlatformHasProvider($team, $provider)) {
                Log::debug('BaseStageJob: Stage model tier resolved', [
                    'experiment_id' => $this->experimentId,
                    'stage' => $stageKey,
                    'tier' => $tier,
                    'provider' => $provider,
                    'model' => $model,
                ]);

                return ['provider' => $provider, 'model' => $model];
            }
        }

        return null;
    }

    /**
     * Check if a provider is available via platform API key or team BYOK credential.
     */
    private function teamOrPlatformHasProvider(?Team $team, string $provider): bool
    {
        // Platform-level API key
        if (config("services.platform_api_keys.{$provider}")) {
            return true;
        }

        if (! $team) {
            return false;
        }

        return TeamProviderCredential::where('team_id', $team->id)
            ->where('provider', $provider)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Check if this stage should be skipped due to YOLO mode.
     * Testing and validation stages use this to skip when YOLO is active.
     */
    protected function shouldSkipForYolo(Experiment $experiment): bool
    {
        return $experiment->isYoloMode();
    }

    protected function populateSearchableText(Experiment $experiment, ExperimentStage $stage): void
    {
        try {
            $parts = [
                $experiment->title,
                $experiment->thesis ?? '',
                $stage->stage instanceof \BackedEnum ? $stage->stage->value : (string) $stage->stage,
            ];

            $outputText = is_array($stage->output_snapshot)
                ? json_encode($stage->output_snapshot)
                : (string) ($stage->output_snapshot ?? '');

            $parts[] = Str::limit($outputText, 5000, '');

            $stage->update([
                'searchable_text' => implode(' ', array_filter($parts)),
            ]);
        } catch (\Throwable $e) {
            Log::debug('BaseStageJob: Failed to populate searchable_text', [
                'experiment_id' => $experiment->id,
                'stage_id' => $stage->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check the experiment's accumulated LLM context usage after a stage completes.
     * Fires ExperimentContextApproachingLimit when usage >= 80% (warning) or >= 90% (critical).
     * Non-blocking — failures are swallowed to avoid impacting the pipeline.
     */
    protected function checkContextHealth(Experiment $experiment): void
    {
        try {
            $health = app(ContextHealthService::class)->getExperimentContextHealth($experiment);

            if ($health->isApproachingLimit) {
                Log::warning('BaseStageJob: Experiment context approaching limit', [
                    'experiment_id' => $experiment->id,
                    'stage' => $this->stageType()->value,
                    'context_used_pct' => $health->contextUsedPercent(),
                    'total_input_tokens' => $health->totalInputTokens,
                    'context_window' => $health->contextWindowTokens,
                    'model' => $health->primaryModel,
                    'level' => $health->level(),
                ]);

                event(new ExperimentContextApproachingLimit($experiment, $health));

                // At critical level (>= 90%), persist a handoff document artifact so a
                // fresh context can resume the experiment coherently.
                if ($health->isCritical) {
                    $handoff = app(ContextHealthService::class)->buildHandoffDocument($experiment);
                    app(CheckpointManager::class)->saveContextHandoff($experiment, $handoff);
                }
            }
        } catch (\Throwable $e) {
            Log::debug('BaseStageJob: Context health check failed (non-blocking)', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function resolveVerifier(): ?StageVerifier
    {
        return match ($this->stageType()) {
            StageType::Scoring => new ScoringVerifier,
            StageType::Planning => new PlanningVerifier,
            StageType::Building => new BuildingVerifier,
            StageType::Executing => new ExecutingVerifier,
            StageType::CollectingMetrics => new MetricsVerifier,
            StageType::Evaluating => new EvaluatingVerifier,
        };
    }

    /**
     * @return array{passed: bool, errors: array<string>}
     */
    protected function verifyStageOutput(Experiment $experiment, ExperimentStage $stage): array
    {
        $verifier = $this->resolveVerifier();

        if (! $verifier) {
            return ['passed' => true, 'errors' => []];
        }

        return $verifier->verify($experiment, $stage);
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
