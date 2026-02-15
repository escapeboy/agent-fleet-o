<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Memory\Models\Memory;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

class MemoryTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function tools(): array
    {
        return [
            self::searchMemories(),
            self::listRecentMemories(),
            self::getMemoryStats(),
        ];
    }

    private static function searchMemories(): PrismToolObject
    {
        return PrismTool::as('search_memories')
            ->for('Search through agent memories by keyword. Returns memories matching the search term.')
            ->withStringParameter('query', 'Search term to find in memory content (required)')
            ->withStringParameter('agent_id', 'Filter by agent UUID (optional)')
            ->withNumberParameter('limit', 'Max results (default 10)')
            ->using(function (string $query, ?string $agent_id = null, ?int $limit = null) {
                $dbQuery = Memory::query()
                    ->with(['agent:id,name', 'project:id,title'])
                    ->where('content', 'ilike', "%{$query}%")
                    ->orderByDesc('created_at');

                if ($agent_id) {
                    $dbQuery->where('agent_id', $agent_id);
                }

                $memories = $dbQuery->limit($limit ?? 10)->get();

                return json_encode([
                    'count' => $memories->count(),
                    'memories' => $memories->map(fn ($m) => [
                        'id' => $m->id,
                        'agent' => $m->agent?->name ?? 'N/A',
                        'project' => $m->project?->title ?? 'N/A',
                        'source_type' => $m->source_type,
                        'content' => Str::limit($m->content, 300),
                        'created' => $m->created_at->diffForHumans(),
                    ])->toArray(),
                ]);
            });
    }

    private static function listRecentMemories(): PrismToolObject
    {
        return PrismTool::as('list_recent_memories')
            ->for('List the most recent agent memories, optionally filtered by agent or source type')
            ->withStringParameter('agent_id', 'Filter by agent UUID (optional)')
            ->withStringParameter('source_type', 'Filter by source type, e.g. experiment_execution, skill_execution (optional)')
            ->withNumberParameter('limit', 'Max results (default 10)')
            ->using(function (?string $agent_id = null, ?string $source_type = null, ?int $limit = null) {
                $query = Memory::query()
                    ->with(['agent:id,name', 'project:id,title'])
                    ->orderByDesc('created_at');

                if ($agent_id) {
                    $query->where('agent_id', $agent_id);
                }

                if ($source_type) {
                    $query->where('source_type', $source_type);
                }

                $memories = $query->limit($limit ?? 10)->get();

                return json_encode([
                    'count' => $memories->count(),
                    'memories' => $memories->map(fn ($m) => [
                        'id' => $m->id,
                        'agent' => $m->agent?->name ?? 'N/A',
                        'project' => $m->project?->title ?? 'N/A',
                        'source_type' => $m->source_type,
                        'content' => Str::limit($m->content, 300),
                        'created' => $m->created_at->diffForHumans(),
                    ])->toArray(),
                ]);
            });
    }

    private static function getMemoryStats(): PrismToolObject
    {
        return PrismTool::as('get_memory_stats')
            ->for('Get memory statistics: total count, per-agent breakdown, and source type distribution')
            ->using(function () {
                $total = Memory::count();

                $byAgent = Memory::query()
                    ->join('agents', 'memories.agent_id', '=', 'agents.id')
                    ->selectRaw('agents.name, count(*) as count')
                    ->groupBy('agents.name')
                    ->orderByDesc('count')
                    ->limit(10)
                    ->pluck('count', 'name')
                    ->toArray();

                $bySource = Memory::query()
                    ->selectRaw('source_type, count(*) as count')
                    ->groupBy('source_type')
                    ->orderByDesc('count')
                    ->pluck('count', 'source_type')
                    ->toArray();

                return json_encode([
                    'total_memories' => $total,
                    'by_agent' => $byAgent,
                    'by_source_type' => $bySource,
                ]);
            });
    }
}
