<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Memory\Models\Memory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ListRecentMemoriesTool implements Tool
{
    public function name(): string
    {
        return 'list_recent_memories';
    }

    public function description(): string
    {
        return 'List the most recent agent memories, optionally filtered by agent or source type';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('Filter by agent UUID (optional)'),
            'source_type' => $schema->string()->description('Filter by source type, e.g. experiment_execution, skill_execution (optional)'),
            'limit' => $schema->integer()->description('Max results (default 10)'),
        ];
    }

    public function handle(Request $request): string
    {
        $query = Memory::query()
            ->with(['agent:id,name', 'project:id,title'])
            ->orderByDesc('created_at');

        if ($request->get('agent_id')) {
            $query->where('agent_id', $request->get('agent_id'));
        }

        if ($request->get('source_type')) {
            $query->where('source_type', $request->get('source_type'));
        }

        $memories = $query->limit($request->get('limit', 10))->get();

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
