<?php

namespace App\Domain\Project\Actions;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMilestone;
use App\Domain\Project\Models\ProjectSchedule;
use App\Domain\Project\Models\ProjectSnapshot;
use BackedEnum;

/**
 * Capture a Project's full configuration into a restorable snapshot.
 * Kanwas-inspired sprint — workspace version history.
 *
 * The snapshot records configuration only (not runtime counters, status, or
 * timestamps). Milestones are captured for display/diff but are not re-applied
 * on restore — they carry run-linked completion state.
 */
class CreateProjectSnapshotAction
{
    /** Config keys that round-trip through snapshot → restore. */
    public const PROJECT_CONFIG_KEYS = [
        'title', 'description', 'goal', 'type', 'execution_mode',
        'agent_config', 'budget_config', 'notification_config',
        'delivery_config', 'settings', 'allowed_tool_ids',
        'allowed_credential_ids', 'crew_id', 'workflow_id',
        'website_id', 'email_template_id', 'meta',
    ];

    public function execute(Project $project, ?string $label = null, ?string $createdBy = null): ProjectSnapshot
    {
        // Re-load from the database so column defaults (e.g. execution_mode)
        // are captured even when the caller passes a partially-hydrated model.
        $project = $project->fresh() ?? $project;

        $milestones = ProjectMilestone::query()
            ->where('project_id', $project->id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ProjectMilestone $m) => [
                'title' => $m->title,
                'description' => $m->description,
                'criteria' => $m->criteria,
                'sort_order' => $m->sort_order,
            ])->values()->all();

        $snapshot = [
            'version' => 1,
            'project' => [
                'title' => $project->title,
                'description' => $project->description,
                'goal' => $project->goal,
                'type' => $this->enumValue($project->type),
                'execution_mode' => $this->enumValue($project->execution_mode),
                'agent_config' => $project->agent_config,
                'budget_config' => $project->budget_config,
                'notification_config' => $project->notification_config,
                'delivery_config' => $project->delivery_config,
                'settings' => $project->settings,
                'allowed_tool_ids' => $project->allowed_tool_ids,
                'allowed_credential_ids' => $project->allowed_credential_ids,
                'crew_id' => $project->crew_id,
                'workflow_id' => $project->workflow_id,
                'website_id' => $project->website_id,
                'email_template_id' => $project->email_template_id,
                'meta' => $project->getAttribute('meta'),
            ],
            'schedule' => $this->captureSchedule($project),
            'milestones' => $milestones,
        ];

        $resolvedLabel = $label !== null && trim($label) !== ''
            ? trim($label)
            : 'Snapshot '.now()->format('Y-m-d H:i');

        return ProjectSnapshot::create([
            'team_id' => $project->team_id,
            'project_id' => $project->id,
            'created_by' => $createdBy,
            'label' => $resolvedLabel,
            'snapshot' => $snapshot,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function captureSchedule(Project $project): ?array
    {
        $schedule = ProjectSchedule::query()
            ->where('project_id', $project->id)
            ->first();

        if (! $schedule) {
            return null;
        }

        return [
            'frequency' => $this->enumValue($schedule->frequency),
            'cron_expression' => $schedule->cron_expression,
            'interval_minutes' => $schedule->interval_minutes,
            'timezone' => $schedule->timezone,
            'overlap_policy' => $this->enumValue($schedule->overlap_policy),
            'max_consecutive_failures' => $schedule->max_consecutive_failures,
            'catchup_missed' => $schedule->catchup_missed,
            'run_immediately' => $schedule->run_immediately,
            'overrides' => $schedule->overrides,
            'enabled' => $schedule->enabled,
            'heartbeat_enabled' => $schedule->heartbeat_enabled,
            'heartbeat_interval_minutes' => $schedule->heartbeat_interval_minutes,
            'heartbeat_budget_cap' => $schedule->heartbeat_budget_cap,
            'heartbeat_context_sources' => $schedule->heartbeat_context_sources,
        ];
    }

    /**
     * Resolve a backed enum (or plain string) attribute to its string value.
     */
    private function enumValue(BackedEnum|string|null $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return $value;
    }
}
