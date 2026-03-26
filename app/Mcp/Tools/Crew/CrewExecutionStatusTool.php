<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\CrewExecution;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class CrewExecutionStatusTool extends Tool
{
    protected string $name = 'crew_execution_status';

    protected string $description = 'Poll the status of a crew execution. Returns execution details including status, goal, and result preview.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'execution_id' => $schema->string()
                ->description('The crew execution UUID')
                ->required(),
            'include_full_output' => $schema->boolean()
                ->description('Include full final_output instead of 500-char preview (default false)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'execution_id' => 'required|string',
            'include_full_output' => 'sometimes|boolean',
        ]);

        $teamId = auth()->user()?->current_team_id;

        $execution = CrewExecution::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->withCount('artifacts')
            ->find($validated['execution_id']);

        if (! $execution) {
            return Response::error('Crew execution not found.');
        }

        $result = $execution->final_output;
        $includeFullOutput = $validated['include_full_output'] ?? false;

        if ($includeFullOutput) {
            $resultText = $result
                ? (is_array($result) ? json_encode($result, JSON_PRETTY_PRINT) : (string) $result)
                : null;
        } else {
            $resultText = $result
                ? mb_substr(is_array($result) ? json_encode($result) : (string) $result, 0, 500)
                : null;
        }

        $taskExecutions = $execution->taskExecutions()
            ->orderBy('sort_order')
            ->get(['id', 'title', 'status', 'sort_order', 'depends_on', 'agent_id', 'started_at', 'completed_at']);

        return Response::text(json_encode([
            'id' => $execution->id,
            'status' => $execution->status->value,
            'crew_id' => $execution->crew_id,
            'goal' => $execution->goal,
            'result' => $resultText,
            'artifacts_count' => $execution->artifacts_count,
            'blocked_count' => $execution->taskExecutions()
                ->where('status', CrewTaskStatus::Blocked->value)
                ->count(),
            'tasks' => $taskExecutions->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'status' => $t->status->value,
                'sort_order' => $t->sort_order,
                'depends_on' => $t->depends_on ?? [],
                'started_at' => $t->started_at?->toIso8601String(),
                'completed_at' => $t->completed_at?->toIso8601String(),
            ]),
            'created_at' => $execution->created_at?->toIso8601String(),
            'updated_at' => $execution->updated_at?->toIso8601String(),
        ]));
    }
}
