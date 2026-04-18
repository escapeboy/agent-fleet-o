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
class MemoryExportTool extends Tool
{
    protected string $name = 'memory_export';

    protected string $description = 'Export all memory entries for the team as a JSON array (for backup).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'topic' => $schema->string()->description('Filter by topic.'),
            'limit' => $schema->integer()->description('Maximum number of entries to return (default 500).')->default(500),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $limit = min((int) ($request->get('limit', 500)), 1000);

        $query = Memory::withoutGlobalScopes()->where('team_id', $teamId);

        if ($topic = $request->get('topic')) {
            $query->where('topic', $topic);
        }

        $entries = $query->latest()->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $entries->count(),
            'entries' => $entries->map(fn ($m) => [
                'id' => $m->id,
                'content' => $m->content,
                'topic' => $m->topic,
                'tags' => $m->tags,
                'content_hash' => $m->content_hash,
                'created_at' => $m->created_at,
                'updated_at' => $m->updated_at,
            ])->toArray(),
        ]));
    }
}
