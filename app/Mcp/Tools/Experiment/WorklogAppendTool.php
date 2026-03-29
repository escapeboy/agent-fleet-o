<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\WorklogEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WorklogAppendTool extends Tool
{
    protected string $name = 'worklog_append';

    protected string $description = 'Append a typed worklog entry during agent execution. Use to record structured reasoning: references consulted, findings discovered, decisions made, uncertainties encountered, or outputs produced.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->description('Entry type: reference, finding, decision, uncertainty, or output')
                ->required(),
            'content' => $schema->string()
                ->description('The worklog entry text')
                ->required(),
            'workloggable_type' => $schema->string()
                ->description('Parent context type: experiment_stage or crew_task_execution'),
            'workloggable_id' => $schema->string()
                ->description('UUID of the parent experiment stage or crew task execution'),
            'metadata_json' => $schema->string()
                ->description('Optional JSON string with additional structured metadata'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'type' => 'required|string',
            'content' => 'required|string',
            'workloggable_type' => 'nullable|string|in:experiment_stage,crew_task_execution',
            'workloggable_id' => 'nullable|string',
            'metadata_json' => 'nullable|string',
        ]);

        if (! in_array($validated['type'], WorklogEntry::validTypes())) {
            return Response::error('Invalid type. Must be one of: '.implode(', ', WorklogEntry::validTypes()));
        }

        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $metadata = null;
        if (! empty($validated['metadata_json'])) {
            $metadata = json_decode($validated['metadata_json'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return Response::error('Invalid JSON in metadata_json: '.json_last_error_msg());
            }
        }

        $morphTypeMap = [
            'experiment_stage' => ExperimentStage::class,
            'crew_task_execution' => CrewTaskExecution::class,
        ];

        $workloggableType = null;
        $workloggableId = null;

        if (! empty($validated['workloggable_type']) && ! empty($validated['workloggable_id'])) {
            $workloggableType = $morphTypeMap[$validated['workloggable_type']] ?? null;
            $workloggableId = $validated['workloggable_id'];
        }

        $entry = WorklogEntry::create([
            'team_id' => $teamId,
            'workloggable_type' => $workloggableType,
            'workloggable_id' => $workloggableId,
            'type' => $validated['type'],
            'content' => $validated['content'],
            'metadata' => $metadata,
        ]);

        return Response::text(json_encode([
            'success' => true,
            'entry_id' => $entry->id,
            'type' => $entry->type,
            'content' => $entry->content,
        ]));
    }
}
