<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\WorklogEntry;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class WorklogReadTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'worklog_read';

    protected string $description = 'Read worklog entries for an experiment stage or crew task execution. Returns last 50 entries ordered by creation time, optionally filtered by type.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workloggable_type' => $schema->string()
                ->description('Parent context type: experiment_stage or crew_task_execution')
                ->required(),
            'workloggable_id' => $schema->string()
                ->description('UUID of the parent experiment stage or crew task execution')
                ->required(),
            'type_filter' => $schema->string()
                ->description('Optional: filter by entry type (reference, finding, decision, uncertainty, output)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'workloggable_type' => 'required|string|in:experiment_stage,crew_task_execution',
            'workloggable_id' => 'required|string',
            'type_filter' => 'nullable|string',
        ]);

        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $morphTypeMap = [
            'experiment_stage' => ExperimentStage::class,
            'crew_task_execution' => CrewTaskExecution::class,
        ];

        $morphType = $morphTypeMap[$validated['workloggable_type']];

        $query = WorklogEntry::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('workloggable_type', $morphType)
            ->where('workloggable_id', $validated['workloggable_id'])
            ->orderBy('created_at')
            ->orderBy('id');

        if (! empty($validated['type_filter'])) {
            if (! in_array($validated['type_filter'], WorklogEntry::validTypes())) {
                return $this->invalidArgumentError('Invalid type_filter. Must be one of: '.implode(', ', WorklogEntry::validTypes()));
            }
            $query->where('type', $validated['type_filter']);
        }

        $entries = $query->limit(50)->get(['id', 'type', 'content', 'metadata', 'created_at']);

        return Response::text(json_encode([
            'count' => $entries->count(),
            'entries' => $entries->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'content' => $e->content,
                'metadata' => $e->metadata,
                'created_at' => $e->created_at?->toISOString(),
            ])->values()->all(),
        ]));
    }
}
