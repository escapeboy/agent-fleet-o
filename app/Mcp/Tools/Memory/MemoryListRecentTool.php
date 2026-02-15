<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Models\Memory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class MemoryListRecentTool extends Tool
{
    protected string $name = 'memory_list_recent';

    protected string $description = 'List recent agent memories with optional filters. Returns memories with agent and project context.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('Filter by agent UUID'),
            'source_type' => $schema->string()
                ->description('Filter by source type (e.g. execution, manual, signal)'),
            'limit' => $schema->integer()
                ->description('Max results to return (default 10, max 100)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = Memory::query()
            ->with(['agent:id,name', 'project:id,title'])
            ->orderByDesc('created_at');

        if ($agentId = $request->get('agent_id')) {
            $query->where('agent_id', $agentId);
        }

        if ($sourceType = $request->get('source_type')) {
            $query->where('source_type', $sourceType);
        }

        $limit = min((int) ($request->get('limit', 10)), 100);

        $memories = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $memories->count(),
            'memories' => $memories->map(fn ($m) => [
                'id' => $m->id,
                'agent' => $m->agent?->name,
                'project' => $m->project?->title,
                'source_type' => $m->source_type,
                'content' => mb_substr($m->content, 0, 300),
                'created' => $m->created_at?->diffForHumans(),
            ])->toArray(),
        ]));
    }
}
