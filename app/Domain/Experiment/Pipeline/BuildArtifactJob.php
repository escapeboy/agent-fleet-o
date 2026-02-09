<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Enums\ExperimentTaskStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentTask;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Jobs\Middleware\CheckBudgetAvailable;
use App\Jobs\Middleware\CheckKillSwitch;
use App\Jobs\Middleware\TenantRateLimit;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use App\Models\GlobalSetting;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BuildArtifactJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 540;
    public int $backoff = 30;

    public function __construct(
        public readonly string $experimentId,
        public readonly string $taskId,
        public readonly ?string $teamId = null,
    ) {
        $this->onQueue('ai-calls');
    }

    public function middleware(): array
    {
        return [
            new CheckKillSwitch(),
            new CheckBudgetAvailable(),
            new TenantRateLimit('ai-calls', 30),
        ];
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $task = ExperimentTask::withoutGlobalScopes()->find($this->taskId);
        if (! $task || $task->status === ExperimentTaskStatus::Completed) {
            return;
        }

        $experiment = Experiment::withoutGlobalScopes()->find($this->experimentId);
        if (! $experiment) {
            return;
        }

        // Resolve LLM early to check for local agent serialization
        $llm = $this->resolveLlm($task, $experiment);

        // Serialize local agent calls — the bridge/CLI handles one request at a time.
        // If the lock is held, release the job back to the queue to avoid timeout.
        $lock = null;
        if (str_starts_with($llm['provider'], 'local/')) {
            $lock = Cache::store('redis')->lock('local-agent-bridge', 600);
            if (! $lock->get()) {
                if ($task->status === ExperimentTaskStatus::Pending) {
                    $task->update(['status' => ExperimentTaskStatus::Queued]);
                }
                $this->release(15);

                return;
            }
        }

        // Mark task as running
        $task->update([
            'status' => ExperimentTaskStatus::Running,
            'started_at' => now(),
            'provider' => $llm['provider'],
            'model' => $llm['model'],
        ]);

        $startTime = hrtime(true);

        try {
            $gateway = app(AiGatewayInterface::class);

            $inputData = $task->input_data ?? [];
            $plan = $inputData['plan'] ?? [];
            $artifactSpec = $inputData['artifact_spec'] ?? [];

            $request = new AiRequestDTO(
                provider: $llm['provider'],
                model: $llm['model'],
                systemPrompt: 'You are a content builder agent. Generate the requested artifact content. Return a JSON object with: content (string - the actual artifact content), metadata (object - any relevant metadata about the artifact).',
                userPrompt: "Build this artifact:\n\nType: {$artifactSpec['type']}\nName: {$artifactSpec['name']}\nDescription: {$artifactSpec['description']}\n\nExperiment context:\nTitle: {$experiment->title}\nThesis: {$experiment->thesis}\nPlan: " . json_encode($plan),
                maxTokens: 2048,
                userId: $experiment->user_id,
                teamId: $experiment->team_id,
                experimentId: $experiment->id,
                purpose: 'building',
                temperature: 0.7,
            );

            $response = $gateway->complete($request);
            $output = $response->parsedOutput ?? json_decode($response->content, true);

            // Create artifact and version
            $artifact = Artifact::withoutGlobalScopes()->create([
                'team_id' => $experiment->team_id,
                'experiment_id' => $experiment->id,
                'type' => $artifactSpec['type'] ?? 'unknown',
                'name' => $artifactSpec['name'] ?? $task->name,
                'current_version' => 1,
                'metadata' => $output['metadata'] ?? [],
            ]);

            ArtifactVersion::withoutGlobalScopes()->create([
                'team_id' => $experiment->team_id,
                'artifact_id' => $artifact->id,
                'version' => 1,
                'content' => $output['content'] ?? $response->content,
                'metadata' => ['iteration' => $experiment->current_iteration],
            ]);

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $task->update([
                'status' => ExperimentTaskStatus::Completed,
                'output_data' => [
                    'artifact_id' => $artifact->id,
                    'type' => $artifact->type,
                    'name' => $artifact->name,
                ],
                'duration_ms' => $durationMs,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $task->update([
                'status' => ExperimentTaskStatus::Failed,
                'error' => $e->getMessage(),
                'duration_ms' => $durationMs,
                'completed_at' => now(),
            ]);

            Log::error('BuildArtifactJob: Failed to build artifact', [
                'task_id' => $this->taskId,
                'experiment_id' => $this->experimentId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $lock?->release();
        }
    }

    /**
     * Called when the job fails after all retries or is killed by timeout.
     * Ensures the ExperimentTask is marked as Failed so it doesn't stay "running" forever.
     */
    public function failed(?\Throwable $exception): void
    {
        $task = ExperimentTask::withoutGlobalScopes()->find($this->taskId);
        if (! $task || $task->status === ExperimentTaskStatus::Completed || $task->status === ExperimentTaskStatus::Failed) {
            return;
        }

        $task->update([
            'status' => ExperimentTaskStatus::Failed,
            'error' => $exception ? substr($exception->getMessage(), 0, 500) : 'Job killed by worker (timeout or OOM)',
            'completed_at' => now(),
        ]);

        Log::warning('BuildArtifactJob: Job failed permanently', [
            'task_id' => $this->taskId,
            'experiment_id' => $this->experimentId,
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Resolve LLM provider/model for this task.
     * Priority: task agent → experiment constraints → team default → platform default.
     *
     * @return array{provider: string, model: string}
     */
    private function resolveLlm(ExperimentTask $task, Experiment $experiment): array
    {
        // 1. Task-level agent override
        if ($task->agent_id) {
            $agent = $task->agent;
            if ($agent && $agent->llm_provider && $agent->llm_model) {
                return [
                    'provider' => $agent->llm_provider,
                    'model' => $agent->llm_model,
                ];
            }
        }

        // 2. Experiment-level override
        $constraints = $experiment->constraints ?? [];
        if (! empty($constraints['llm']['provider']) && ! empty($constraints['llm']['model'])) {
            return [
                'provider' => $constraints['llm']['provider'],
                'model' => $constraints['llm']['model'],
            ];
        }

        // 3. Team-level default
        $team = Team::withoutGlobalScopes()->find($experiment->team_id);
        $settings = $team?->settings ?? [];
        if (! empty($settings['default_llm_provider']) && ! empty($settings['default_llm_model'])) {
            return [
                'provider' => $settings['default_llm_provider'],
                'model' => $settings['default_llm_model'],
            ];
        }

        // 4. Platform default
        $platformProvider = GlobalSetting::get('default_llm_provider') ?? config('llm_pricing.default_provider', 'anthropic');
        $platformModel = GlobalSetting::get('default_llm_model') ?? config('llm_pricing.default_model', 'claude-sonnet-4-5');

        return [
            'provider' => $platformProvider,
            'model' => $platformModel,
        ];
    }
}
