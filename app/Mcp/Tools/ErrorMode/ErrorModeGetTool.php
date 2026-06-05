<?php

namespace App\Mcp\Tools\ErrorMode;

use App\Domain\ErrorMode\Models\ErrorMode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ErrorModeGetTool extends Tool
{
    protected string $name = 'error_mode_get';

    protected string $description = 'Get one error mode with its example trace ids and linked evaluation-case count.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'error_mode_id' => $schema->string()
                ->description('Error mode ID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::text(json_encode(['error' => 'No current team.']));
        }

        $mode = ErrorMode::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->withCount('evaluationCases')
            ->find($request->get('error_mode_id'));

        if (! $mode) {
            return Response::text(json_encode(['error' => 'Error mode not found.']));
        }

        return Response::text(json_encode([
            'id' => $mode->id,
            'slug' => $mode->slug,
            'name' => $mode->name,
            'description' => $mode->description,
            // Enums and Carbon dates serialize to their scalar form via json_encode.
            'lever' => $mode->lever,
            'status' => $mode->status,
            'occurrence_count' => $mode->occurrence_count,
            'evaluation_cases_count' => $mode->evaluation_cases_count,
            'example_trace_ids' => $mode->example_trace_ids,
            'first_seen_at' => $mode->first_seen_at,
            'last_seen_at' => $mode->last_seen_at,
        ]));
    }
}
