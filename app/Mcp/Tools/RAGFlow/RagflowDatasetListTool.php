<?php

namespace App\Mcp\Tools\RAGFlow;

use App\Domain\Knowledge\Models\KnowledgeBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class RagflowDatasetListTool extends Tool
{
    protected string $name = 'ragflow_dataset_list';

    protected string $description = 'List all knowledge bases that have RAGFlow enabled for this team, with their dataset IDs, chunk methods, and sync status.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id');

        $bases = KnowledgeBase::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('ragflow_enabled', true)
            ->get(['id', 'name', 'status', 'ragflow_dataset_id', 'ragflow_chunk_method', 'ragflow_last_synced_at']);

        return Response::text(json_encode([
            'count' => $bases->count(),
            'datasets' => $bases->map(fn ($kb) => [
                'knowledge_base_id' => $kb->id,
                'name' => $kb->name,
                'status' => $kb->status,
                'ragflow_dataset_id' => $kb->ragflow_dataset_id,
                'chunk_method' => $kb->ragflow_chunk_method,
                'last_synced_at' => $kb->ragflow_last_synced_at?->toISOString(),
            ])->values(),
        ]));
    }
}
