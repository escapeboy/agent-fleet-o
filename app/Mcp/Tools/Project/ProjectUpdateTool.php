<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Actions\UpdateProjectAction;
use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ProjectUpdateTool extends Tool
{
    protected string $name = 'project_update';

    protected string $description = 'Update an existing project. Only provided fields will be changed.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('The project UUID')
                ->required(),
            'title' => $schema->string()
                ->description('New project title'),
            'description' => $schema->string()
                ->description('New project description'),
            'goal' => $schema->string()
                ->description('New project goal'),
            'execution_mode' => $schema->string()
                ->description('Execution mode: autonomous (full tool access) or watcher (read-only tools only)')
                ->enum(['autonomous', 'watcher']),
            'workflow_id' => $schema->string()
                ->description('UUID of an active workflow to assign to this project. Must be in active status. Pass empty string to detach.'),
            'crew_id' => $schema->string()
                ->description('UUID of a crew to assign to this project. Pass empty string to detach.'),
            'allowed_tool_ids' => $schema->array()
                ->description('Restrict which tools agents can use. Pass an array of tool UUIDs. Empty array = all team tools allowed.')
                ->items($schema->string()),
            'allowed_credential_ids' => $schema->array()
                ->description('Restrict which credentials are available to agents. Pass an array of credential UUIDs.')
                ->items($schema->string()),
            'schedule' => $schema->object([
                'frequency' => $schema->string()
                    ->enum(['every_5_minutes', 'every_10_minutes', 'every_15_minutes', 'every_30_minutes', 'hourly', 'daily', 'weekly', 'monthly', 'cron', 'once']),
                'cron_expression' => $schema->string()
                    ->description('Raw 5-part cron expression. Required when frequency=cron.'),
                'timezone' => $schema->string()
                    ->description('IANA timezone, e.g. "Europe/London".'),
                'overlap_policy' => $schema->string()
                    ->enum(['skip', 'queue', 'allow']),
                'max_consecutive_failures' => $schema->integer(),
            ])
                ->description('Update schedule for continuous projects. Only provided sub-fields are changed.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project_id' => 'required|string',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'goal' => 'nullable|string',
            'execution_mode' => 'nullable|string|in:autonomous,watcher',
            'workflow_id' => 'nullable|string',
            'crew_id' => 'nullable|string',
            'allowed_tool_ids' => 'nullable|array',
            'allowed_tool_ids.*' => 'uuid',
            'allowed_credential_ids' => 'nullable|array',
            'allowed_credential_ids.*' => 'uuid',
            'schedule' => 'nullable|array',
            'schedule.frequency' => 'nullable|string|in:every_5_minutes,every_10_minutes,every_15_minutes,every_30_minutes,hourly,daily,weekly,monthly,cron,once',
            'schedule.cron_expression' => 'nullable|string',
            'schedule.timezone' => 'nullable|string',
            'schedule.overlap_policy' => 'nullable|string|in:skip,queue,allow',
            'schedule.max_consecutive_failures' => 'nullable|integer|min:1',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $project = Project::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['project_id']);

        if (! $project) {
            return Response::error('Project not found.');
        }

        $data = array_filter([
            'title' => $validated['title'] ?? null,
            'description' => $validated['description'] ?? null,
            'goal' => $validated['goal'] ?? null,
            'execution_mode' => $validated['execution_mode'] ?? null,
            'schedule' => $validated['schedule'] ?? null,
        ], fn ($v) => $v !== null);

        // workflow_id and crew_id can be empty string to detach
        if (array_key_exists('workflow_id', $validated)) {
            $data['workflow_id'] = $validated['workflow_id'] ?: null;
        }
        if (array_key_exists('crew_id', $validated)) {
            $data['crew_id'] = $validated['crew_id'] ?: null;
        }
        if (array_key_exists('allowed_tool_ids', $validated)) {
            $data['allowed_tool_ids'] = $validated['allowed_tool_ids'];
        }
        if (array_key_exists('allowed_credential_ids', $validated)) {
            $data['allowed_credential_ids'] = $validated['allowed_credential_ids'];
        }

        if (empty($data)) {
            return Response::error('No fields to update. Provide at least one of: title, description, goal, execution_mode, workflow_id, crew_id, allowed_tool_ids, allowed_credential_ids, schedule.');
        }

        try {
            $result = app(UpdateProjectAction::class)->execute(
                project: $project,
                data: $data,
            );

            $response = [
                'success' => true,
                'project_id' => $result->id,
                'title' => $result->title,
                'execution_mode' => $result->execution_mode->value,
                'workflow_id' => $result->workflow_id,
                'crew_id' => $result->crew_id,
                'allowed_tool_ids' => $result->allowed_tool_ids ?? [],
                'allowed_credential_ids' => $result->allowed_credential_ids ?? [],
                'updated_fields' => array_keys($data),
            ];

            if ($result->schedule) {
                $response['schedule'] = [
                    'frequency' => $result->schedule->frequency->value,
                    'timezone' => $result->schedule->timezone,
                    'next_run_at' => $result->schedule->next_run_at?->toIso8601String(),
                    'enabled' => $result->schedule->enabled,
                ];
            }

            return Response::text(json_encode($response));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
