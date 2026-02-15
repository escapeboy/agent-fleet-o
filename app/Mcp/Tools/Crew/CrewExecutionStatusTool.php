<?php

namespace App\Mcp\Tools\Crew;

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
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['execution_id' => 'required|string']);

        $execution = CrewExecution::find($validated['execution_id']);

        if (! $execution) {
            return Response::error('Crew execution not found.');
        }

        $result = $execution->final_output;
        $resultPreview = $result
            ? mb_substr(is_array($result) ? json_encode($result) : (string) $result, 0, 500)
            : null;

        return Response::text(json_encode([
            'id' => $execution->id,
            'status' => $execution->status->value,
            'crew_id' => $execution->crew_id,
            'goal' => $execution->goal,
            'result' => $resultPreview,
            'created_at' => $execution->created_at?->toIso8601String(),
            'updated_at' => $execution->updated_at?->toIso8601String(),
        ]));
    }
}
