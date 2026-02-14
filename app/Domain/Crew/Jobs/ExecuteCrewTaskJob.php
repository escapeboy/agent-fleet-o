<?php

namespace App\Domain\Crew\Jobs;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Crew\Services\CrewOrchestrator;
use App\Jobs\Middleware\CheckBudgetAvailable;
use App\Jobs\Middleware\CheckKillSwitch;
use App\Jobs\Middleware\TenantRateLimit;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExecuteCrewTaskJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public int $backoff = 30;

    public function __construct(
        public readonly string $crewExecutionId,
        public readonly string $taskExecutionId,
        public readonly ?string $teamId = null,
    ) {
        $this->onQueue('ai-calls');
    }

    public function middleware(): array
    {
        return [
            new CheckKillSwitch,
            new CheckBudgetAvailable,
            new TenantRateLimit('ai-calls', 30),
        ];
    }

    public function handle(ExecuteAgentAction $executeAgent): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $taskExecution = CrewTaskExecution::withoutGlobalScopes()->find($this->taskExecutionId);
        $execution = CrewExecution::withoutGlobalScopes()->find($this->crewExecutionId);

        if (! $taskExecution || ! $execution) {
            Log::warning('Crew task or execution not found', [
                'task_id' => $this->taskExecutionId,
                'execution_id' => $this->crewExecutionId,
            ]);

            return;
        }

        $agent = Agent::withoutGlobalScopes()->find($taskExecution->agent_id);

        if (! $agent) {
            $this->failTask($taskExecution, $execution, 'Assigned agent not found.');

            return;
        }

        $startTime = hrtime(true);
        $taskExecution->update([
            'status' => CrewTaskStatus::Running,
            'started_at' => now(),
        ]);

        try {
            // Build input from task description + context
            $input = [
                'task' => $taskExecution->title,
                'description' => $taskExecution->description,
                'context' => $taskExecution->input_context ?? [],
            ];

            // Include QA feedback from previous attempt if this is a retry
            if ($taskExecution->attempt_number > 1 && $taskExecution->qa_feedback) {
                $input['previous_feedback'] = $taskExecution->qa_feedback;
                $input['retry_instructions'] = 'This is retry attempt #'.$taskExecution->attempt_number
                    .'. Please address the feedback from the previous attempt.';
            }

            $result = $executeAgent->execute(
                agent: $agent,
                input: $input,
                teamId: $execution->team_id,
                userId: $execution->crew?->user_id ?? $execution->team_id,
            );

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            if ($result['output'] === null) {
                $this->failTask($taskExecution, $execution,
                    $result['execution']->error_message ?? 'Agent execution returned no output.',
                    $durationMs, $result['execution']->cost_credits ?? 0,
                );

                return;
            }

            $taskExecution->update([
                'status' => CrewTaskStatus::Completed,
                'output' => $result['output'],
                'cost_credits' => $result['execution']->cost_credits ?? 0,
                'duration_ms' => $durationMs,
                'completed_at' => now(),
            ]);

            // Track cost on crew execution
            $execution->increment('total_cost_credits', $result['execution']->cost_credits ?? 0);

            // Dispatch QA validation
            ValidateCrewTaskJob::dispatch(
                crewExecutionId: $this->crewExecutionId,
                taskExecutionId: $this->taskExecutionId,
                teamId: $this->teamId,
            );
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $this->failTask($taskExecution, $execution, $e->getMessage(), $durationMs);
        }
    }

    private function failTask(
        CrewTaskExecution $task,
        CrewExecution $execution,
        string $error,
        int $durationMs = 0,
        int $costCredits = 0,
    ): void {
        $task->update([
            'status' => CrewTaskStatus::Failed,
            'error_message' => $error,
            'duration_ms' => $durationMs,
            'cost_credits' => $costCredits,
            'completed_at' => now(),
        ]);

        $execution->increment('total_cost_credits', $costCredits);

        Log::warning('Crew task execution failed', [
            'task_id' => $task->id,
            'execution_id' => $execution->id,
            'error' => $error,
        ]);

        // Notify orchestrator of failure
        app(CrewOrchestrator::class)
            ->onTaskFailed($execution, $task->fresh());
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExecuteCrewTaskJob failed permanently', [
            'task_id' => $this->taskExecutionId,
            'execution_id' => $this->crewExecutionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
