<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Memory\Models\Memory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GetMemoryStatsTool implements Tool
{
    public function name(): string
    {
        return 'get_memory_stats';
    }

    public function description(): string
    {
        return 'Get memory statistics: total count, per-agent breakdown, and source type distribution';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
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
    }
}
