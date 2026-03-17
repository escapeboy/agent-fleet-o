<?php

namespace App\Mcp\Tools\Knowledge;

use App\Domain\Knowledge\Actions\SearchKnowledgeAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class KnowledgeBaseSearchTool extends Tool
{
    protected string $name = 'knowledge_base_search';

    protected string $description = 'Semantic search across a knowledge base using vector similarity. Returns the most relevant text chunks with source attribution and similarity score.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'knowledge_base_id' => $schema->string()
                ->description('UUID of the knowledge base to search')
                ->required(),
            'query' => $schema->string()
                ->description('Natural language query')
                ->required(),
            'top_k' => $schema->integer()
                ->description('Number of results to return (default 5, max 20)')
                ->default(5),
        ];
    }

    public function handle(Request $request, SearchKnowledgeAction $action): Response
    {
        $request->validate([
            'knowledge_base_id' => 'required|string',
            'query' => 'required|string|min:1',
        ]);

        $results = $action->execute(
            knowledgeBaseId: $request->get('knowledge_base_id'),
            query: $request->get('query'),
            topK: min((int) ($request->get('top_k', 5)), 20),
        );

        return Response::text(json_encode([
            'count' => count($results),
            'results' => $results,
        ]));
    }
}
