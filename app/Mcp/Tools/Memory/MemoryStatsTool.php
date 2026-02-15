<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Models\Memory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class MemoryStatsTool extends Tool
{
    protected string $name = 'memory_stats';

    protected string $description = 'Get memory statistics: total count, breakdown by agent (top 10), and breakdown by source type.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $totalMemories = Memory::query()->count();

        $byAgent = DB::table('memories')
            ->join('agents', 'memories.agent_id', '=', 'agents.id')
            ->select('agents.name', DB::raw('count(*) as count'))
            ->groupBy('agents.name')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'count' => (int) $row->count,
            ])
            ->toArray();

        $bySourceType = DB::table('memories')
            ->select('source_type', DB::raw('count(*) as count'))
            ->groupBy('source_type')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'source_type' => $row->source_type,
                'count' => (int) $row->count,
            ])
            ->toArray();

        return Response::text(json_encode([
            'total_memories' => $totalMemories,
            'by_agent' => $byAgent,
            'by_source_type' => $bySourceType,
        ]));
    }
}
