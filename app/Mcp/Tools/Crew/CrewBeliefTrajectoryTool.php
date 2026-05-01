<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Models\CrewExecution;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class CrewBeliefTrajectoryTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'crew_belief_trajectory';

    protected string $description = 'Return the belief state trajectory (stance, confidence) for each task in a crew execution, ordered by sort_order. Useful for analyzing agent confidence trends.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'execution_id' => $schema->string()
                ->description('The crew execution UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        $executionId = $request->get('execution_id');

        $execution = CrewExecution::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($executionId);

        if (! $execution) {
            return $this->notFoundError('crew execution');
        }

        $trajectory = $execution->taskExecutions()
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($task) => [
                'sort_order' => $task->sort_order,
                'title' => $task->title,
                'status' => $task->status->value,
                'belief_state' => $task->belief_state,
                'completed_at' => $task->completed_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Response::text(json_encode([
            'execution_id' => $executionId,
            'task_count' => count($trajectory),
            'trajectory' => $trajectory,
        ], JSON_PRETTY_PRINT));
    }
}
