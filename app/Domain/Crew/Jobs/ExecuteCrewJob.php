<?php

namespace App\Domain\Crew\Jobs;

use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Services\CrewOrchestrator;
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

class ExecuteCrewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly string $crewExecutionId,
        public readonly ?string $teamId = null,
    ) {
        $this->onQueue('experiments');
    }

    public function middleware(): array
    {
        return [
            new CheckKillSwitch(),
            new CheckBudgetAvailable(),
            new TenantRateLimit('experiments', 30),
            (new WithoutOverlapping($this->crewExecutionId))->releaseAfter(600),
        ];
    }

    public function handle(CrewOrchestrator $orchestrator): void
    {
        $execution = CrewExecution::withoutGlobalScopes()->find($this->crewExecutionId);

        if (! $execution) {
            Log::warning('Crew execution not found', ['id' => $this->crewExecutionId]);

            return;
        }

        if ($execution->status !== CrewExecutionStatus::Planning) {
            Log::info('Crew execution not in planning state, skipping', [
                'id' => $this->crewExecutionId,
                'status' => $execution->status->value,
            ]);

            return;
        }

        $orchestrator->run($execution);
    }

    public function failed(\Throwable $exception): void
    {
        $execution = CrewExecution::withoutGlobalScopes()->find($this->crewExecutionId);

        if ($execution && $execution->status !== CrewExecutionStatus::Failed) {
            $execution->update([
                'status' => CrewExecutionStatus::Failed,
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);
        }

        Log::error('ExecuteCrewJob failed', [
            'execution_id' => $this->crewExecutionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
