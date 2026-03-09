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
            'min_confidence' => $schema->number()
                ->description('Minimum confidence score to filter results (0.0–1.0, default 0.0 to include all)')
                ->default(0.0),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['query' => 'required|string']);

        $teamId = auth()->user()?->current_team_id;

        $query = Memory::withoutGlobalScopes()
            ->with(['agent:id,name', 'project:id,title'])
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->where('content', 'ilike', '%'.addcslashes($validated['query'], '%_').'%')
            ->orderByDesc('created_at');

        if ($agentId = $request->get('agent_id')) {
            $query->where('agent_id', $agentId);
        }

        $minConfidence = (float) $request->get('min_confidence', 0.0);
        if ($minConfidence > 0.0) {
            $query->where('confidence', '>=', $minConfidence);
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
                'confidence' => $m->confidence,
                'tags' => $m->tags ?? [],
                'created' => $m->created_at?->diffForHumans(),
            ])->toArray(),
        ]));
    }
}
