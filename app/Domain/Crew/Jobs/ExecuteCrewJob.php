<?php

namespace App\Domain\Crew\Jobs;

use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Services\CrewOrchestrator;
use App\Infrastructure\AI\Services\PhoenixTraceContext;
use App\Infrastructure\Telemetry\Sentry\SentryEventCapturer;
use App\Jobs\Middleware\CheckBudgetAvailable;
use App\Jobs\Middleware\CheckKillSwitch;
use App\Jobs\Middleware\HasSentryContext;
use App\Jobs\Middleware\SentryContextJobMiddleware;
use App\Jobs\Middleware\TenantRateLimit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExecuteCrewJob implements HasSentryContext, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly string $crewExecutionId,
        public readonly ?string $teamId = null,
        public readonly bool $resume = false,
    ) {
        $this->onQueue('experiments');
    }

    public function sentryContext(): array
    {
        return [
            'sub_program' => 'crew.task',
            'team_id' => $this->teamId,
            'crew_execution_id' => $this->crewExecutionId,
        ];
    }

    public function middleware(): array
    {
        // A resume job that finds the original job still running (still planning) is
        // dropped instead of released: that planner re-reads the status after planning
        // and dispatches the tasks itself. Releasing would exceed $tries and fail the run.
        $overlap = new WithoutOverlapping($this->crewExecutionId);
        $overlap = $this->resume ? $overlap->dontRelease() : $overlap->releaseAfter(600);

        return [
            app(SentryContextJobMiddleware::class),
            new CheckKillSwitch,
            new CheckBudgetAvailable,
            new TenantRateLimit('experiments', 30),
            $overlap,
        ];
    }

    public function handle(CrewOrchestrator $orchestrator, PhoenixTraceContext $trace): void
    {
        $execution = CrewExecution::withoutGlobalScopes()->find($this->crewExecutionId);

        if (! $execution) {
            Log::warning('Crew execution not found', ['id' => $this->crewExecutionId]);

            return;
        }

        $expected = $this->resume ? CrewExecutionStatus::Executing : CrewExecutionStatus::Planning;

        if ($execution->getAttribute('status') !== $expected) {
            Log::info('Crew execution not in expected state, skipping', [
                'id' => $this->crewExecutionId,
                'status' => $execution->status->value,
                'expected' => $expected->value,
            ]);

            return;
        }

        $trace->withRoot('crew.execute', [
            'metadata.crew_execution_id' => $this->crewExecutionId,
            'metadata.team_id' => $this->teamId,
        ], function () use ($orchestrator, $execution) {
            if ($this->resume) {
                $orchestrator->resume($execution);

                return;
            }

            $orchestrator->run($execution);
        });
    }

    public function failed(\Throwable $exception): void
    {
        $captured = app(SentryEventCapturer::class)->capture($exception, [
            'context' => $this->sentryContext(),
        ]);

        $execution = CrewExecution::withoutGlobalScopes()->find($this->crewExecutionId);

        if ($execution && $execution->status !== CrewExecutionStatus::Failed) {
            $execution->update([
                'status' => CrewExecutionStatus::Failed,
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
                'error_metadata' => array_replace(
                    is_array($execution->error_metadata) ? $execution->error_metadata : [],
                    $captured->toMetadata($exception),
                ),
            ]);
        }

        Log::error('ExecuteCrewJob failed', [
            'execution_id' => $this->crewExecutionId,
            'error' => $exception->getMessage(),
            'sentry_event_id' => $captured->eventId,
        ]);
    }
}
