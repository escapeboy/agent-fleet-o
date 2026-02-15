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
class MemorySearchTool extends Tool
{
    protected string $name = 'memory_search';

    protected string $description = 'Search agent memories by keyword. Returns matching memories with agent and project context.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search keyword to match against memory content')
                ->required(),
            'agent_id' => $schema->string()
                ->description('Filter by agent UUID'),
            'limit' => $schema->integer()
                ->description('Max results to return (default 10, max 100)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['query' => 'required|string']);

        $query = Memory::query()
            ->with(['agent:id,name', 'project:id,title'])
            ->where('content', 'ilike', '%'.addcslashes($validated['query'], '%_').'%')
            ->orderByDesc('created_at');

        if ($agentId = $request->get('agent_id')) {
            $query->where('agent_id', $agentId);
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
