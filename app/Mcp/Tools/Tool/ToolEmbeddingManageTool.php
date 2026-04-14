<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool as ToolModel;
use App\Domain\Tool\Models\ToolEmbedding;
use App\Domain\Tool\Services\SemanticToolSelector;
use App\Domain\Tool\Services\ToolTranslator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use App\Mcp\Attributes\AssistantTool;

#[IsDestructive]
#[AssistantTool('write')]
class ToolEmbeddingManageTool extends Tool
{
    protected string $name = 'tool_embedding_manage';

    protected string $description = 'Generate or refresh vector embeddings for tool definitions. Use action "embed" to generate embeddings for a specific tool or all active tools. Use "remove" to delete embeddings. Use "stats" to view embedding coverage.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action to perform')
                ->enum(['embed', 'embed_all', 'remove', 'stats'])
                ->required(),
            'tool_id' => $schema->string()
                ->description('Tool ID (required for embed and remove actions)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $action = $request->get('action');
        $teamId = auth()->user()?->currentTeam?->id;

        return match ($action) {
            'embed' => $this->embedTool($request->get('tool_id'), $teamId),
            'embed_all' => $this->embedAll($teamId),
            'remove' => $this->removeTool($request->get('tool_id')),
            'stats' => $this->stats($teamId),
            default => Response::text(json_encode(['error' => "Unknown action: {$action}"])),
        };
    }

    private function embedTool(?string $toolId, ?string $teamId): Response
    {
        if (! $toolId) {
            return Response::text(json_encode(['error' => 'tool_id is required for embed action']));
        }

        $tool = ToolModel::withoutGlobalScopes()->where('team_id', $teamId)->find($toolId);
        if (! $tool) {
            return Response::text(json_encode(['error' => 'Tool not found']));
        }

        $translator = app(ToolTranslator::class);
        $prismTools = $translator->toPrismTools($tool);

        $defs = array_map(fn ($pt) => [
            'name' => $pt->name(),
            'description' => $pt->description(),
        ], $prismTools);

        $count = app(SemanticToolSelector::class)->embedToolDefinitions($tool->id, $tool->team_id, $defs);

        return Response::text(json_encode([
            'embedded' => $count,
            'tool_name' => $tool->name,
        ]));
    }

    private function embedAll(?string $teamId): Response
    {
        $selector = app(SemanticToolSelector::class);
        $translator = app(ToolTranslator::class);
        $total = 0;

        ToolModel::where('status', ToolStatus::Active)
            ->chunk(50, function ($tools) use ($selector, $translator, &$total) {
                foreach ($tools as $tool) {
                    $prismTools = $translator->toPrismTools($tool);
                    $defs = array_map(fn ($pt) => [
                        'name' => $pt->name(),
                        'description' => $pt->description(),
                    ], $prismTools);

                    if (! empty($defs)) {
                        $total += $selector->embedToolDefinitions($tool->id, $tool->team_id, $defs);
                    }
                }
            });

        return Response::text(json_encode(['embedded' => $total]));
    }

    private function removeTool(?string $toolId): Response
    {
        if (! $toolId) {
            return Response::text(json_encode(['error' => 'tool_id is required for remove action']));
        }

        $deleted = app(SemanticToolSelector::class)->removeToolEmbeddings($toolId);

        return Response::text(json_encode(['deleted' => $deleted]));
    }

    private function stats(?string $teamId): Response
    {
        $query = ToolEmbedding::withoutGlobalScopes();
        if ($teamId) {
            $query->where(fn ($q) => $q->where('team_id', $teamId)->orWhereNull('team_id'));
        }

        $total = $query->count();
        $withEmbedding = (clone $query)->whereNotNull('embedding')->count();
        $toolCount = (clone $query)->distinct('tool_id')->count('tool_id');

        return Response::text(json_encode([
            'total_embeddings' => $total,
            'with_vector' => $withEmbedding,
            'tools_covered' => $toolCount,
        ]));
    }
}
