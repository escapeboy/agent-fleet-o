<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Models\Memory;
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
class MemoryGetTool extends Tool
{
    protected string $name = 'memory_get';

    protected string $description = 'Get a specific memory entry by ID.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'memory_id' => $schema->string()->description('The memory entry ID.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $memory = Memory::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('memory_id'));
        if (! $memory) {
            return Response::error('Memory entry not found.');
        }

        return Response::text(json_encode([
            'id' => $memory->id,
            'content' => $memory->content,
            'topic' => $memory->topic,
            'tags' => $memory->tags,
            'content_hash' => $memory->content_hash,
            'created_at' => $memory->created_at,
            'updated_at' => $memory->updated_at,
        ]));
    }
}
