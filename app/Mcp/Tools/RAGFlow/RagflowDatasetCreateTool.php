<?php

namespace App\Mcp\Tools\RAGFlow;

use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Infrastructure\RAGFlow\RAGFlowClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class RagflowDatasetCreateTool extends Tool
{
    protected string $name = 'ragflow_dataset_create';

    protected string $description = 'Enable RAGFlow deep document understanding on a knowledge base. Creates a RAGFlow dataset and links it to the knowledge base. Supports 12 domain-specific chunking templates: general, paper, book, laws, qa, table, naive, manual, picture, one, email, presentation.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'knowledge_base_id' => $schema->string()
                ->description('UUID of the knowledge base to enable RAGFlow on')
                ->required(),
            'chunk_method' => $schema->string()
                ->description('Chunking template. Options: general (default), paper, book, laws, qa, table, naive, manual, picture, one, email, presentation')
                ->default('general'),
        ];
    }

    public function handle(Request $request, RAGFlowClient $client): Response
    {
        $request->validate([
            'knowledge_base_id' => 'required|string',
        ]);

        $teamId = app('mcp.team_id');
        $kb = KnowledgeBase::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $request->get('knowledge_base_id'))
            ->firstOrFail();

        $chunkMethod = $request->get('chunk_method', 'general');

        // Create dataset on RAGFlow
        $dataset = $client->createDataset(
            name: $kb->name,
            chunkMethod: $chunkMethod,
        );

        $datasetId = $dataset['id'] ?? $dataset['dataset_id'] ?? null;

        if (! $datasetId) {
            return Response::text(json_encode(['error' => 'RAGFlow returned no dataset ID']));
        }

        $kb->update([
            'ragflow_enabled' => true,
            'ragflow_dataset_id' => $datasetId,
            'ragflow_chunk_method' => $chunkMethod,
        ]);

        return Response::text(json_encode([
            'knowledge_base_id' => $kb->id,
            'ragflow_dataset_id' => $datasetId,
            'chunk_method' => $chunkMethod,
            'message' => 'RAGFlow enabled. Re-ingest documents to use DeepDoc parsing.',
        ]));
    }
}
