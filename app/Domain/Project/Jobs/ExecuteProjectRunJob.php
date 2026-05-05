<?php

namespace App\Domain\Project\Jobs;

use App\Domain\Project\Actions\TriggerProjectRunAction;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Services\ProjectScheduler;
use App\Jobs\Middleware\ApplyTenantTracer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExecuteProjectRunJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly string $projectId,
        public readonly string $trigger = 'schedule',
    ) {
        $this->onQueue('experiments');
    }

    public function middleware(): array
    {
        return [new ApplyTenantTracer];
    }

    /** Used by ApplyTenantTracer middleware to route spans to the right team's OTLP backend. */
    public function teamId(): ?string
    {
        return Project::withoutGlobalScopes()->where('id', $this->projectId)->value('team_id');
    }

    public function handle(TriggerProjectRunAction $triggerAction): void
    {
        // Guard: projectId must be a valid UUID, not a serialized model or JSON blob
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $this->projectId)) {
            Log::error('ExecuteProjectRunJob: projectId is not a valid UUID — job was dispatched with wrong argument', [
                'projectId_preview' => mb_substr($this->projectId, 0, 100),
                'trigger' => $this->trigger,
            ]);

            return;
        }

        $project = Project::withoutGlobalScopes()->find($this->projectId);

        if (! $project) {
            Log::warning("ExecuteProjectRunJob: Project {$this->projectId} not found");

            return;
        }

        if (! $project->status->isActive()) {
            Log::info("ExecuteProjectRunJob: Project {$this->projectId} is no longer active (status: {$project->status->value})");

            return;
        }

        $triggerAction->execute($project, $this->trigger);

        // Advance schedule AFTER the run is created (not before, as was previously
        // done in ProjectScheduler::processProject). This prevents phantom last_run_at
        // when the job fails before the run record exists.
        if ($this->trigger === 'schedule' && $project->schedule) {
            app(ProjectScheduler::class)->advanceSchedule($project);
        }
    }
}
