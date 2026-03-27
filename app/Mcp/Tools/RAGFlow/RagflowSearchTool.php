<?php

namespace App\Mcp\Tools\RAGFlow;

use App\Domain\Knowledge\Actions\SearchRagflowAction;
use App\Domain\Knowledge\Models\KnowledgeBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class RagflowSearchTool extends Tool
{
    protected string $name = 'ragflow_search';

    protected string $description = 'Hybrid BM25+vector retrieval from a RAGFlow knowledge base. Supports knowledge graph traversal (use_kg=true) for entity-aware search. Returns ranked chunks with source attribution and optional page positions from DeepDoc.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'knowledge_base_id' => $schema->string()
                ->description('UUID of the RAGFlow-enabled knowledge base')
                ->required(),
            'query' => $schema->string()
                ->description('Natural language query')
                ->required(),
            'top_k' => $schema->integer()
                ->description('Number of chunks to return (default 8, max 30)')
                ->default(8),
            'use_kg' => $schema->boolean()
                ->description('Include knowledge graph traversal for entity-aware retrieval (requires GraphRAG to be built first)')
                ->default(false),
            'similarity_threshold' => $schema->number()
                ->description('Minimum similarity score 0.0–1.0 (default 0.2)')
                ->default(0.2),
            'vector_weight' => $schema->number()
                ->description('Balance between BM25 and vector: 0.0 = pure BM25, 1.0 = pure vector (default 0.3)')
                ->default(0.3),
        ];
    }

    public function handle(Request $request, SearchRagflowAction $action): Response
    {
        $request->validate([
            'knowledge_base_id' => 'required|string',
            'query' => 'required|string|min:1',
        ]);

        $teamId = app('mcp.team_id');
        $kb = KnowledgeBase::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $request->get('knowledge_base_id'))
            ->where('ragflow_enabled', true)
            ->firstOrFail();

        $results = $action->execute($kb, $request->get('query'), [
            'top_k' => min((int) $request->get('top_k', 8), 30),
            'use_kg' => (bool) $request->get('use_kg', false),
            'similarity_threshold' => (float) $request->get('similarity_threshold', 0.2),
            'vector_similarity_weight' => (float) $request->get('vector_weight', 0.3),
        ]);

        return Response::text(json_encode([
            'count' => count($results),
            'results' => $results,
        ]));
    }
}
