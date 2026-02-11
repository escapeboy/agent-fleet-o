<?php

namespace App\Domain\Project\Actions;

use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use Illuminate\Support\Facades\DB;

class ArchiveProjectAction
{
    public function execute(Project $project): Project
    {
        return DB::transaction(function () use ($project) {
            $project = Project::withoutGlobalScopes()->lockForUpdate()->findOrFail($project->id);

            if ($project->status === ProjectStatus::Archived) {
                return $project;
            }

            $previousStatus = $project->status->value;

            $project->update([
                'status' => ProjectStatus::Archived,
                'completed_at' => $project->completed_at ?? now(),
            ]);

            // Disable schedule
            if ($project->schedule) {
                $project->schedule->update(['enabled' => false]);
            }

            activity()
                ->performedOn($project)
                ->withProperties(['from_status' => $previousStatus])
                ->log('project.archived');

            return $project->fresh();
        });
    }
}
