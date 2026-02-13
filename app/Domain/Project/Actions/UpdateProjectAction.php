<?php

namespace App\Domain\Project\Actions;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectSchedule;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Support\Facades\DB;

class UpdateProjectAction
{
    public function execute(
        Project $project,
        array $data,
    ): Project {
        return DB::transaction(function () use ($project, $data) {
            // Validate workflow is active if being changed
            if (isset($data['workflow_id']) && $data['workflow_id']) {
                $workflow = Workflow::find($data['workflow_id']);
                if (! $workflow || ! $workflow->isActive()) {
                    throw new \InvalidArgumentException('The selected workflow must be active.');
                }
            }

            // Update core project fields
            $project->update(array_filter([
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'workflow_id' => array_key_exists('workflow_id', $data) ? ($data['workflow_id'] ?: null) : $project->workflow_id,
                'agent_config' => $data['agent_config'] ?? $project->agent_config,
                'budget_config' => $data['budget_config'] ?? $project->budget_config,
                'notification_config' => $data['notification_config'] ?? $project->notification_config,
                'delivery_config' => array_key_exists('delivery_config', $data) ? $data['delivery_config'] : $project->delivery_config,
            ], fn ($v) => $v !== null));

            // Update schedule if provided
            if (isset($data['schedule']) && $project->isContinuous()) {
                $schedule = $project->schedule;
                $scheduleData = $data['schedule'];

                if ($schedule) {
                    $schedule->update([
                        'frequency' => $scheduleData['frequency'] ?? $schedule->frequency->value,
                        'cron_expression' => $scheduleData['cron_expression'] ?? $schedule->cron_expression,
                        'timezone' => $scheduleData['timezone'] ?? $schedule->timezone,
                        'overlap_policy' => $scheduleData['overlap_policy'] ?? $schedule->overlap_policy->value,
                        'max_consecutive_failures' => $scheduleData['max_consecutive_failures'] ?? $schedule->max_consecutive_failures,
                    ]);

                    // Recalculate next run
                    $nextRun = $schedule->fresh()->calculateNextRunAt();
                    if ($nextRun) {
                        $schedule->update(['next_run_at' => $nextRun]);
                        $project->update(['next_run_at' => $nextRun]);
                    }
                } else {
                    // Create schedule if one doesn't exist yet
                    $newSchedule = ProjectSchedule::create([
                        'project_id' => $project->id,
                        'frequency' => $scheduleData['frequency'] ?? 'daily',
                        'cron_expression' => $scheduleData['cron_expression'] ?? null,
                        'timezone' => $scheduleData['timezone'] ?? 'UTC',
                        'overlap_policy' => $scheduleData['overlap_policy'] ?? 'skip',
                        'max_consecutive_failures' => $scheduleData['max_consecutive_failures'] ?? 3,
                    ]);

                    $nextRun = $newSchedule->calculateNextRunAt();
                    if ($nextRun) {
                        $newSchedule->update(['next_run_at' => $nextRun]);
                        $project->update(['next_run_at' => $nextRun]);
                    }
                }
            }

            return $project->fresh(['schedule']);
        });
    }
}
