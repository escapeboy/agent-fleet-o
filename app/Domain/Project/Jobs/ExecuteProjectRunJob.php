<?php

namespace App\Domain\Project\Jobs;

use App\Domain\Project\Actions\TriggerProjectRunAction;
use App\Domain\Project\Models\Project;
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

    public function handle(TriggerProjectRunAction $triggerAction): void
    {
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
    }
}
