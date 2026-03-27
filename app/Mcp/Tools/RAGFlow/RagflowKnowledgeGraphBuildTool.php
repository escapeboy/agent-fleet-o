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
class RagflowKnowledgeGraphBuildTool extends Tool
{
    protected string $name = 'ragflow_knowledge_graph_build';

    protected string $description = 'Trigger GraphRAG knowledge graph construction for a RAGFlow dataset. Extracts entities and relationships from all documents, then deduplicates and clusters them. Once built, enables use_kg=true in ragflow_search. Strategies: general (Microsoft GraphRAG prompts) or light (LightRAG, faster/cheaper).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'knowledge_base_id' => $schema->string()
                ->description('UUID of the RAGFlow-enabled knowledge base')
                ->required(),
            'strategy' => $schema->string()
                ->description('GraphRAG strategy: general (thorough, default) or light (faster, less memory)')
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
            ->where('ragflow_enabled', true)
            ->firstOrFail();

        $strategy = in_array($request->get('strategy', 'general'), ['general', 'light'])
            ? $request->get('strategy', 'general')
            : 'general';

        $client->buildKnowledgeGraph($kb->ragflow_dataset_id, $strategy);

        return Response::text(json_encode([
            'knowledge_base_id' => $kb->id,
            'dataset_id' => $kb->ragflow_dataset_id,
            'strategy' => $strategy,
            'message' => 'GraphRAG build triggered asynchronously. Use ragflow_knowledge_graph_status to check progress. Once complete, use use_kg=true in ragflow_search.',
        ]));
    }
}
