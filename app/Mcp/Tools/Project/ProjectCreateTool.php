<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Actions\CreateProjectAction;
use App\Domain\Project\Enums\ProjectExecutionMode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ProjectCreateTool extends Tool
{
    protected string $name = 'project_create';

    protected string $description = 'Create a new project. One-shot projects auto-start immediately. Continuous projects require a schedule config to run on a recurring basis — without it, they will never execute.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('Project title')
                ->required(),
            'description' => $schema->string()
                ->description('Project description'),
            'type' => $schema->string()
                ->description('Project type: one_shot (runs once immediately), continuous (recurring via schedule). Default: one_shot')
                ->enum(['one_shot', 'continuous'])
                ->default('one_shot'),
            'goal' => $schema->string()
                ->description('Project goal'),
            'execution_mode' => $schema->string()
                ->description('Execution mode: autonomous (full tool access) or watcher (read-only tools only). Default: autonomous')
                ->enum(['autonomous', 'watcher'])
                ->default('autonomous'),
            'workflow_id' => $schema->string()
                ->description('UUID of an active workflow to run for each project execution. The workflow must be in active status.'),
            'crew_id' => $schema->string()
                ->description('UUID of a crew to run for each project execution. Alternative to workflow_id.'),
            'allowed_tool_ids' => $schema->array()
                ->description('Restrict which tools agents can use in this project. Pass an array of tool UUIDs. Empty = all team tools allowed.')
                ->items($schema->string()),
            'allowed_credential_ids' => $schema->array()
                ->description('Restrict which credentials are available to agents in this project. Pass an array of credential UUIDs.')
                ->items($schema->string()),
            'schedule' => $schema->object()
                ->description('Schedule configuration. Required for continuous projects — omitting it creates a project that never runs. Use project_schedule_nlp to parse natural language schedules.')
                ->properties([
                    'frequency' => $schema->string()
                        ->description('Preset frequency. Use "cron" to specify a custom cron_expression.')
                        ->enum(['every_5_minutes', 'every_10_minutes', 'every_15_minutes', 'every_30_minutes', 'hourly', 'daily', 'weekly', 'monthly', 'cron', 'once'])
                        ->default('daily'),
                    'cron_expression' => $schema->string()
                        ->description('Raw cron expression (5-part). Required when frequency=cron, e.g. "0 9 * * 1" for every Monday at 9am.'),
                    'timezone' => $schema->string()
                        ->description('IANA timezone, e.g. "Europe/London". Default: UTC')
                        ->default('UTC'),
                    'overlap_policy' => $schema->string()
                        ->description('What to do when a run is already active at the scheduled time. skip=do nothing, queue=run after current finishes, allow=always run. Default: skip')
                        ->enum(['skip', 'queue', 'allow'])
                        ->default('skip'),
                    'max_consecutive_failures' => $schema->integer()
                        ->description('Pause the project after this many consecutive failed runs. Default: 3')
                        ->default(3),
                    'catchup_missed' => $schema->boolean()
                        ->description('Dispatch missed runs when the project resumes from pause. Default: false')
                        ->default(false),
                    'run_immediately' => $schema->boolean()
                        ->description('Trigger a run immediately on creation, before the first scheduled time. Default: true')
                        ->default(true),
                ]),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|string|in:one_shot,continuous',
            'goal' => 'nullable|string',
            'execution_mode' => 'nullable|string|in:autonomous,watcher',
            'workflow_id' => 'nullable|uuid|exists:workflows,id',
            'crew_id' => 'nullable|uuid|exists:crews,id',
            'allowed_tool_ids' => 'nullable|array',
            'allowed_tool_ids.*' => 'uuid|exists:tools,id',
            'allowed_credential_ids' => 'nullable|array',
            'allowed_credential_ids.*' => 'uuid|exists:credentials,id',
            'schedule' => 'nullable|array',
            'schedule.frequency' => 'nullable|string|in:every_5_minutes,every_10_minutes,every_15_minutes,every_30_minutes,hourly,daily,weekly,monthly,cron,once',
            'schedule.cron_expression' => 'nullable|string',
            'schedule.timezone' => 'nullable|string',
            'schedule.overlap_policy' => 'nullable|string|in:skip,queue,allow',
            'schedule.max_consecutive_failures' => 'nullable|integer|min:1',
            'schedule.catchup_missed' => 'nullable|boolean',
            'schedule.run_immediately' => 'nullable|boolean',
        ]);

        try {
            $project = app(CreateProjectAction::class)->execute(
                userId: auth()->id(),
                title: $validated['title'],
                type: $validated['type'] ?? 'one_shot',
                description: $validated['description'] ?? null,
                goal: $validated['goal'] ?? null,
                teamId: auth()->user()->current_team_id,
                workflowId: $validated['workflow_id'] ?? null,
                crewId: $validated['crew_id'] ?? null,
                executionMode: isset($validated['execution_mode'])
                    ? ProjectExecutionMode::from($validated['execution_mode'])
                    : null,
                schedule: $validated['schedule'] ?? null,
            );

            // Update allowed_tool_ids and allowed_credential_ids if provided
            $allowedFields = array_filter([
                'allowed_tool_ids' => isset($validated['allowed_tool_ids']) ? $validated['allowed_tool_ids'] : null,
                'allowed_credential_ids' => isset($validated['allowed_credential_ids']) ? $validated['allowed_credential_ids'] : null,
            ], fn ($v) => $v !== null);

            if (! empty($allowedFields)) {
                $project->update($allowedFields);
            }

            $response = [
                'success' => true,
                'project_id' => $project->id,
                'title' => $project->title,
                'status' => $project->status->value,
                'execution_mode' => $project->execution_mode->value,
                'workflow_id' => $project->workflow_id,
                'crew_id' => $project->crew_id,
                'allowed_tool_ids' => $project->allowed_tool_ids ?? [],
                'allowed_credential_ids' => $project->allowed_credential_ids ?? [],
            ];

            if ($project->schedule) {
                $response['schedule'] = [
                    'frequency' => $project->schedule->frequency->value,
                    'timezone' => $project->schedule->timezone,
                    'next_run_at' => $project->schedule->next_run_at?->toIso8601String(),
                    'enabled' => $project->schedule->enabled,
                ];
            }

            return Response::text(json_encode($response));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
