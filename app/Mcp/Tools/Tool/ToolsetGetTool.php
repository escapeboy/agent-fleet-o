<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Models\Toolset;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ToolsetGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'toolset_get';

    protected string $description = 'Get details of a specific toolset including its tools and agent usage.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'toolset_id' => $schema->string()
                ->description('The toolset UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $toolset = Toolset::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($request->get('toolset_id'));

        if (! $toolset) {
            return $this->notFoundError('toolset');
        }

        return Response::text(json_encode([
            'id' => $toolset->id,
            'name' => $toolset->name,
            'slug' => $toolset->slug,
            'description' => $toolset->description,
            'tool_ids' => $toolset->tool_ids ?? [],
            'tags' => $toolset->tags ?? [],
            'is_platform' => $toolset->is_platform,
            'created_at' => $toolset->created_at?->toIso8601String(),
        ]));
    }
}
