<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Memory\Models\Memory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class SearchMemoriesTool implements Tool
{
    public function name(): string
    {
        return 'search_memories';
    }

    public function description(): string
    {
        return 'Search through agent memories by keyword. Returns memories matching the search term.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description('Search term to find in memory content'),
            'agent_id' => $schema->string()->description('Filter by agent UUID (optional)'),
            'limit' => $schema->integer()->description('Max results (default 10)'),
        ];
    }

    public function handle(Request $request): string
    {
        $dbQuery = Memory::query()
            ->with(['agent:id,name', 'project:id,title'])
            ->where('content', 'ilike', "%{$request->get('query')}%")
            ->orderByDesc('created_at');

        if ($request->get('agent_id')) {
            $dbQuery->where('agent_id', $request->get('agent_id'));
        }

        $memories = $dbQuery->limit($request->get('limit', 10))->get();

        return json_encode([
            'count' => $memories->count(),
            'memories' => $memories->map(fn ($m) => [
                'id' => $m->id,
                'agent' => $m->agent->name ?? 'N/A',
                'project' => $m->project->title ?? 'N/A',
                'source_type' => $m->source_type,
                'content' => Str::limit($m->content, 300),
                'created' => $m->created_at->diffForHumans(),
            ])->toArray(),
        ]);
    }
}
