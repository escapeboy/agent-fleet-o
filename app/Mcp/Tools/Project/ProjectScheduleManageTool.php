<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class ProjectScheduleManageTool extends Tool
{
    protected string $name = 'project_schedule_manage';

    protected string $description = 'Get, update, enable, or disable a project\'s schedule. Returns current schedule state including next_run_at, last_run_at, and the next 5 upcoming run times.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('The project UUID')
                ->required(),
            'operation' => $schema->string()
                ->description('get: read schedule details | update: change schedule settings | enable: turn on the schedule | disable: turn off the schedule')
                ->enum(['get', 'update', 'enable', 'disable'])
                ->required(),
            'schedule' => $schema->object([
                    'frequency' => $schema->string()
                        ->enum(['every_5_minutes', 'every_10_minutes', 'every_15_minutes', 'every_30_minutes', 'hourly', 'daily', 'weekly', 'monthly', 'cron', 'once']),
                    'cron_expression' => $schema->string()
                        ->description('Raw 5-part cron expression. Required when frequency=cron.'),
                    'timezone' => $schema->string()
                        ->description('IANA timezone, e.g. "Europe/Sofia".'),
                    'overlap_policy' => $schema->string()
                        ->enum(['skip', 'queue', 'allow']),
                    'max_consecutive_failures' => $schema->integer(),
                    'catchup_missed' => $schema->boolean(),
                ])
                ->description('New schedule settings. Required when operation=update.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project_id' => 'required|string',
            'operation' => 'required|string|in:get,update,enable,disable',
            'schedule' => 'nullable|array',
            'schedule.frequency' => 'nullable|string|in:every_5_minutes,every_10_minutes,every_15_minutes,every_30_minutes,hourly,daily,weekly,monthly,cron,once',
            'schedule.cron_expression' => 'nullable|string',
            'schedule.timezone' => 'nullable|string',
            'schedule.overlap_policy' => 'nullable|string|in:skip,queue,allow',
            'schedule.max_consecutive_failures' => 'nullable|integer|min:1',
            'schedule.catchup_missed' => 'nullable|boolean',
        ]);

        $project = Project::find($validated['project_id']);

        if (! $project) {
            return Response::error('Project not found.');
        }

        if (! $project->isContinuous()) {
            return Response::error('Only continuous projects have a schedule.');
        }

        $schedule = $project->schedule;

        if (! $schedule) {
            return Response::error('This project has no schedule configured. Use project_update with a schedule object to create one.');
        }

        try {
            return match ($validated['operation']) {
                'get' => $this->getSchedule($schedule),
                'update' => $this->updateSchedule($schedule, $project, $validated['schedule'] ?? []),
                'enable' => $this->setEnabled($schedule, true),
                'disable' => $this->setEnabled($schedule, false),
            };
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }

    private function getSchedule(\App\Domain\Project\Models\ProjectSchedule $schedule): Response
    {
        $upcomingRuns = $schedule->getNextRunTimes(5);

        return Response::text(json_encode([
            'project_id' => $schedule->project_id,
            'frequency' => $schedule->frequency->value,
            'cron_expression' => $schedule->cron_expression,
            'timezone' => $schedule->timezone,
            'overlap_policy' => $schedule->overlap_policy->value,
            'max_consecutive_failures' => $schedule->max_consecutive_failures,
            'catchup_missed' => $schedule->catchup_missed,
            'run_immediately' => $schedule->run_immediately,
            'enabled' => $schedule->enabled,
            'last_run_at' => $schedule->last_run_at?->toIso8601String(),
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            'upcoming_runs' => array_map(fn ($dt) => $dt->toIso8601String(), $upcomingRuns),
        ]));
    }

    private function updateSchedule(
        \App\Domain\Project\Models\ProjectSchedule $schedule,
        Project $project,
        array $data,
    ): Response {
        if (empty($data)) {
            return Response::error('No schedule fields provided to update.');
        }

        $updateData = array_filter([
            'frequency' => $data['frequency'] ?? null,
            'cron_expression' => $data['cron_expression'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'overlap_policy' => $data['overlap_policy'] ?? null,
            'max_consecutive_failures' => $data['max_consecutive_failures'] ?? null,
            'catchup_missed' => $data['catchup_missed'] ?? null,
        ], fn ($v) => $v !== null);

        $schedule->update($updateData);

        // Recalculate next_run_at based on new settings
        $nextRun = $schedule->fresh()->calculateNextRunAt();
        if ($nextRun) {
            $schedule->update(['next_run_at' => $nextRun]);
            $project->update(['next_run_at' => $nextRun]);
        }

        return $this->getSchedule($schedule->fresh());
    }

    private function setEnabled(\App\Domain\Project\Models\ProjectSchedule $schedule, bool $enabled): Response
    {
        $schedule->update(['enabled' => $enabled]);

        return Response::text(json_encode([
            'success' => true,
            'project_id' => $schedule->project_id,
            'enabled' => $enabled,
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
        ]));
    }
}
