<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool as ToolModel;
use App\Domain\Tool\Services\SemanticToolSelector;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class ToolEmbeddingSearchTool extends Tool
{
    protected string $name = 'tool_embedding_search';

    protected string $description = 'Search tool embeddings by semantic similarity. Returns the most relevant tool names for a given query. Useful for discovering which tools are best suited for a task.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Natural language description of the task or capability needed')
                ->required(),
            'limit' => $schema->integer()
                ->description('Max results (default 10)')
                ->default(10),
            'threshold' => $schema->number()
                ->description('Minimum cosine similarity threshold (default 0.75)')
                ->default(0.75),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = auth()->user()?->currentTeam?->id;
        $query = $request->get('query');
        $limit = min((int) ($request->get('limit', 10)), 50);
        $threshold = (float) ($request->get('threshold', 0.75));

        $toolIds = ToolModel::where('status', ToolStatus::Active)
            ->pluck('id')
            ->toArray();

        $matches = app(SemanticToolSelector::class)->searchToolNames(
            $query,
            $teamId ?? '',
            $toolIds,
            $limit,
            $threshold,
        );

        return Response::text(json_encode([
            'query' => $query,
            'matches' => $matches->values()->toArray(),
            'count' => $matches->count(),
        ]));
    }
}
