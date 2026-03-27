<?php

namespace App\Mcp\Tools\RAGFlow;

use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Infrastructure\RAGFlow\RAGFlowClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class RagflowDocumentParseTool extends Tool
{
    protected string $name = 'ragflow_document_parse';

    protected string $description = 'Trigger or re-trigger DeepDoc parsing for documents in a RAGFlow dataset. Can parse all unprocessed documents or a specific subset by document ID.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'knowledge_base_id' => $schema->string()
                ->description('UUID of the RAGFlow-enabled knowledge base')
                ->required(),
            'document_ids' => $schema->array()
                ->description('Optional list of specific document IDs to parse. If omitted, lists all documents in the dataset.')
                ->items($schema->string()),
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
            ->where('ragflow_enabled', true)
            ->firstOrFail();

        $documentIds = $request->get('document_ids', []);

        // If no document IDs provided, list all documents in the dataset
        if (empty($documentIds)) {
            $docsData = $client->listDocuments($kb->ragflow_dataset_id);
            $docs = $docsData['docs'] ?? $docsData['data'] ?? $docsData ?? [];
            $documentIds = array_column($docs, 'id');
        }

        if (empty($documentIds)) {
            return Response::text(json_encode([
                'message' => 'No documents found in dataset to parse.',
                'dataset_id' => $kb->ragflow_dataset_id,
            ]));
        }

        $client->parseDocuments($kb->ragflow_dataset_id, $documentIds);

        return Response::text(json_encode([
            'knowledge_base_id' => $kb->id,
            'dataset_id' => $kb->ragflow_dataset_id,
            'document_count' => count($documentIds),
            'message' => 'Parsing triggered. DeepDoc will process documents asynchronously.',
        ]));
    }
}
