<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Memory\Models\Memory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class DeleteMemoryTool implements Tool
{
    public function name(): string
    {
        return 'delete_memory';
    }

    public function description(): string
    {
        return 'Delete one or more memory records by UUID. Only memories belonging to the current team can be deleted. This is destructive.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'memory_ids' => $schema->string()->required()->description('Comma-separated memory UUIDs (or JSON array) to delete'),
        ];
    }

    public function handle(Request $request): string
    {
        $memoryIds = $request->get('memory_ids');
        $ids = json_decode($memoryIds, true) ?? array_filter(array_map('trim', explode(',', $memoryIds)));
        $teamId = auth()->user()?->current_team_id;

        if (! $teamId) {
            return json_encode(['error' => 'No current team.']);
        }

        try {
            $deleted = Memory::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->whereIn('id', $ids)
                ->delete();

            return json_encode([
                'success' => true,
                'deleted_count' => $deleted,
                'message' => "{$deleted} memory record(s) deleted.",
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
