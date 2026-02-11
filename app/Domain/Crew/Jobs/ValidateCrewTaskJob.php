<?php

namespace App\Domain\Crew\Jobs;

use App\Domain\Crew\Actions\ValidateTaskOutputAction;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Crew\Services\CrewOrchestrator;
use App\Jobs\Middleware\CheckBudgetAvailable;
use App\Jobs\Middleware\CheckKillSwitch;
use App\Jobs\Middleware\TenantRateLimit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ValidateCrewTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 15;

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
            new CheckKillSwitch(),
            new CheckBudgetAvailable(),
            new TenantRateLimit('ai-calls', 30),
        ];
    }

    public function handle(
        ValidateTaskOutputAction $validateAction,
        CrewOrchestrator $orchestrator,
    ): void {
        $taskExecution = CrewTaskExecution::withoutGlobalScopes()->find($this->taskExecutionId);
        $execution = CrewExecution::withoutGlobalScopes()->find($this->crewExecutionId);

        if (! $taskExecution || ! $execution) {
            Log::warning('Crew task or execution not found for validation', [
                'task_id' => $this->taskExecutionId,
                'execution_id' => $this->crewExecutionId,
            ]);

            return;
        }

        try {
            $validation = $validateAction->execute($taskExecution, $execution);

            // Refresh after update
            $taskExecution->refresh();

            if ($validation['passed']) {
                $orchestrator->onTaskValidated($execution, $taskExecution);
            } else {
                $orchestrator->onTaskRejected($execution, $taskExecution);
            }
        } catch (\Throwable $e) {
            Log::error('QA validation failed', [
                'task_id' => $this->taskExecutionId,
                'execution_id' => $this->crewExecutionId,
                'error' => $e->getMessage(),
            ]);

            // QA agent failure is serious — fail the task
            $taskExecution->update([
                'status' => \App\Domain\Crew\Enums\CrewTaskStatus::Failed,
                'error_message' => 'QA validation error: '.$e->getMessage(),
            ]);

            $orchestrator->onTaskFailed($execution, $taskExecution->fresh());
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ValidateCrewTaskJob failed permanently', [
            'task_id' => $this->taskExecutionId,
            'execution_id' => $this->crewExecutionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
