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
            'schedule' => $schema->object()
                ->description('Update schedule for continuous projects. Only provided sub-fields are changed.')
                ->properties([
                    'frequency' => $schema->string()
                        ->enum(['every_5_minutes', 'every_10_minutes', 'every_15_minutes', 'every_30_minutes', 'hourly', 'daily', 'weekly', 'monthly', 'cron', 'once']),
                    'cron_expression' => $schema->string()
                        ->description('Raw 5-part cron expression. Required when frequency=cron.'),
                    'timezone' => $schema->string()
                        ->description('IANA timezone, e.g. "Europe/London".'),
                    'overlap_policy' => $schema->string()
                        ->enum(['skip', 'queue', 'allow']),
                    'max_consecutive_failures' => $schema->integer(),
                ]),
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
            'schedule' => 'nullable|array',
            'schedule.frequency' => 'nullable|string|in:every_5_minutes,every_10_minutes,every_15_minutes,every_30_minutes,hourly,daily,weekly,monthly,cron,once',
            'schedule.cron_expression' => 'nullable|string',
            'schedule.timezone' => 'nullable|string',
            'schedule.overlap_policy' => 'nullable|string|in:skip,queue,allow',
            'schedule.max_consecutive_failures' => 'nullable|integer|min:1',
        ]);

        $project = Project::find($validated['project_id']);

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

        if (empty($data)) {
            return Response::error('No fields to update. Provide at least one of: title, description, goal, execution_mode, schedule.');
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
