<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Models\Toolset;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class ToolsetListTool extends Tool
{
    protected string $name = 'toolset_list';

    protected string $description = 'List toolsets for the current team. Returns id, name, description, tool count, and tags.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Max results (default 20, max 100)')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $limit = min((int) ($request->get('limit', 20)), 100);

        $toolsets = Toolset::query()
            ->withCount('agents')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return Response::text(json_encode([
            'count' => $toolsets->count(),
            'toolsets' => $toolsets->map(fn ($ts) => [
                'id' => $ts->id,
                'name' => $ts->name,
                'description' => $ts->description,
                'tool_count' => count($ts->tool_ids ?? []),
                'tags' => $ts->tags ?? [],
                'agents_count' => $ts->agents_count,
            ])->toArray(),
        ]));
    }
}
