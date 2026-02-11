<?php

namespace App\Domain\Project\Actions;

use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use Illuminate\Support\Facades\DB;

class PauseProjectAction
{
    public function execute(Project $project, ?string $reason = null): Project
    {
        return DB::transaction(function () use ($project, $reason) {
            $project = Project::withoutGlobalScopes()->lockForUpdate()->findOrFail($project->id);

            if (! $project->status->canTransitionTo(ProjectStatus::Paused)) {
                throw new \InvalidArgumentException("Cannot pause project in {$project->status->value} state.");
            }

            $project->update([
                'status' => ProjectStatus::Paused,
                'paused_from_status' => $project->status->value,
                'paused_at' => now(),
            ]);

            // Disable schedule if exists
            if ($project->schedule) {
                $project->schedule->update(['enabled' => false]);
            }

            activity()
                ->performedOn($project)
                ->withProperties(['reason' => $reason, 'from_status' => $project->paused_from_status])
                ->log('project.paused');

            return $project->fresh();
        });
    }
}
