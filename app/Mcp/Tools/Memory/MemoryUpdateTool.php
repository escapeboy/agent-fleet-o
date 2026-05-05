<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Models\Memory;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class MemoryUpdateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'memory_update';

    protected string $description = 'Update the content and/or tags of an existing memory entry.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'memory_id' => $schema->string()->description('The memory entry ID.')->required(),
            'content' => $schema->string()->description('New content for the memory entry.'),
            'tags' => $schema->string()->description('Comma-separated tags for the memory entry.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $memory = Memory::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('memory_id'));
        if (! $memory) {
            return $this->notFoundError('memory entry');
        }

        $updates = [];
        if ($request->get('content') !== null) {
            $updates['content'] = $request->get('content');
        }
        if ($request->get('tags') !== null) {
            $rawTags = $request->get('tags');
            $updates['tags'] = array_values(array_filter(array_map('trim', explode(',', $rawTags))));
        }

        if (! empty($updates)) {
            $memory->update($updates);
        }

        return Response::text(json_encode([
            'success' => true,
            'id' => $memory->id,
            'content' => $memory->content,
            'tags' => $memory->tags,
        ]));
    }
}
