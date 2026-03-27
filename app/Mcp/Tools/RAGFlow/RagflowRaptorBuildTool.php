<?php

namespace App\Mcp\Tools\RAGFlow;

use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Infrastructure\RAGFlow\RAGFlowClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class RagflowRaptorBuildTool extends Tool
{
    protected string $name = 'ragflow_raptor_build';

    protected string $description = 'Trigger RAPTOR (Recursive Abstractive Processing for Tree Organized Retrieval) for a RAGFlow dataset. RAPTOR clusters document chunks, LLM-summarizes each cluster into a parent chunk, and recurses upward to build a hierarchy. This dramatically improves multi-hop QA and cross-document reasoning. Build time scales with corpus size.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'knowledge_base_id' => $schema->string()
                ->description('UUID of the RAGFlow-enabled knowledge base')
                ->required(),
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

        $client->buildRaptor($kb->ragflow_dataset_id);

        return Response::text(json_encode([
            'knowledge_base_id' => $kb->id,
            'dataset_id' => $kb->ragflow_dataset_id,
            'message' => 'RAPTOR build triggered asynchronously. Build time scales with corpus size. Once complete, retrieval queries will match at multiple levels of abstraction — improving multi-hop QA.',
        ]));
    }
}
