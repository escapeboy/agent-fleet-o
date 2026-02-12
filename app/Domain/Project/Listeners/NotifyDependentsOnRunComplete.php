<?php

namespace App\Domain\Project\Listeners;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Project\Models\ProjectDependency;
use App\Domain\Project\Models\ProjectRun;
use Illuminate\Support\Facades\Log;

class NotifyDependentsOnRunComplete
{
    public function handle(ExperimentTransitioned $event): void
    {
        if ($event->toState !== ExperimentStatus::Completed) {
            return;
        }

        $run = ProjectRun::where('experiment_id', $event->experiment->id)->first();

        if (! $run) {
            return;
        }

        $project = $run->project;

        // Find downstream projects that depend on this one
        $downstreamDeps = ProjectDependency::where('depends_on_id', $project->id)
            ->with('project')
            ->get();

        if ($downstreamDeps->isEmpty()) {
            return;
        }

        foreach ($downstreamDeps as $dep) {
            $downstream = $dep->project;

            if (! $downstream) {
                continue;
            }

            activity()
                ->performedOn($downstream)
                ->withProperties([
                    'source_project_id' => $project->id,
                    'source_project_title' => $project->title,
                    'source_run_id' => $run->id,
                    'source_run_number' => $run->run_number,
                    'alias' => $dep->alias,
                ])
                ->log('project.dependency.upstream_completed');

            Log::info("Project {$downstream->id} notified: upstream '{$dep->alias}' ({$project->title}) completed run #{$run->run_number}");
        }
    }
}
