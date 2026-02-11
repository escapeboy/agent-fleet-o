<?php

namespace App\Domain\Project\Actions;

use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use Illuminate\Support\Facades\DB;

class ResumeProjectAction
{
    public function execute(Project $project): Project
    {
        return DB::transaction(function () use ($project) {
            $project = Project::withoutGlobalScopes()->lockForUpdate()->findOrFail($project->id);

            if (! $project->status->canTransitionTo(ProjectStatus::Active)) {
                throw new \InvalidArgumentException("Cannot resume project in {$project->status->value} state.");
            }

            $project->update([
                'status' => ProjectStatus::Active,
                'paused_from_status' => null,
                'paused_at' => null,
            ]);

            // Re-enable and recalculate schedule
            if ($project->schedule) {
                $nextRun = $project->schedule->calculateNextRunAt();
                $project->schedule->update([
                    'enabled' => true,
                    'next_run_at' => $nextRun,
                ]);
                $project->update(['next_run_at' => $nextRun]);
            }

            activity()
                ->performedOn($project)
                ->log('project.resumed');

            return $project->fresh();
        });
    }
}
