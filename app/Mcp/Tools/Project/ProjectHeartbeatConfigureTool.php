<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[IsDestructive]
class ProjectHeartbeatConfigureTool extends Tool
{
    protected string $name = 'project_heartbeat_configure';

    protected string $description = 'Configure heartbeat monitoring for a continuous project. Heartbeat periodically checks context sources (signals, metrics, audit, experiments) and triggers a run only when something needs attention.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('The project UUID')
                ->required(),
            'heartbeat_enabled' => $schema->boolean()
                ->description('Enable or disable heartbeat mode'),
            'heartbeat_interval_minutes' => $schema->integer()
                ->description('How often to run the heartbeat check (in minutes, min 5)'),
            'heartbeat_budget_cap' => $schema->integer()
                ->description('Optional credit cap per heartbeat turn'),
            'heartbeat_context_sources' => $schema->array()
                ->description('Which context sources to check: signals, metrics, audit, experiments'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project_id' => 'required|string',
            'heartbeat_enabled' => 'nullable|boolean',
            'heartbeat_interval_minutes' => 'nullable|integer|min:5',
            'heartbeat_budget_cap' => 'nullable|integer|min:0',
            'heartbeat_context_sources' => 'nullable|array',
            'heartbeat_context_sources.*' => 'string|in:signals,metrics,audit,experiments',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $project = Project::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['project_id']);
        if (! $project) {
            return Response::error('Project not found.');
        }

        if (! $project->isContinuous()) {
            return Response::error('Heartbeat is only available for continuous projects.');
        }

        $schedule = $project->schedule;
        if (! $schedule) {
            return Response::error('This project has no schedule configured.');
        }

        $updateData = array_filter([
            'heartbeat_enabled' => $validated['heartbeat_enabled'] ?? null,
            'heartbeat_interval_minutes' => $validated['heartbeat_interval_minutes'] ?? null,
            'heartbeat_budget_cap' => $validated['heartbeat_budget_cap'] ?? null,
            'heartbeat_context_sources' => $validated['heartbeat_context_sources'] ?? null,
        ], fn ($v) => $v !== null);

        if (empty($updateData)) {
            return Response::text(json_encode([
                'project_id' => $project->id,
                'heartbeat_enabled' => $schedule->heartbeat_enabled,
                'heartbeat_interval_minutes' => $schedule->heartbeat_interval_minutes,
                'heartbeat_budget_cap' => $schedule->heartbeat_budget_cap,
                'heartbeat_context_sources' => $schedule->heartbeat_context_sources,
            ]));
        }

        $schedule->update($updateData);
        $schedule->refresh();

        return Response::text(json_encode([
            'success' => true,
            'project_id' => $project->id,
            'heartbeat_enabled' => $schedule->heartbeat_enabled,
            'heartbeat_interval_minutes' => $schedule->heartbeat_interval_minutes,
            'heartbeat_budget_cap' => $schedule->heartbeat_budget_cap,
            'heartbeat_context_sources' => $schedule->heartbeat_context_sources,
        ]));
    }
}
