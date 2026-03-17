<?php

namespace App\Mcp\Tools\Knowledge;

use App\Domain\Knowledge\Actions\DeleteKnowledgeBaseAction;
use App\Domain\Knowledge\Models\KnowledgeBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class KnowledgeBaseDeleteTool extends Tool
{
    protected string $name = 'knowledge_base_delete';

    protected string $description = 'Delete a knowledge base and all its stored chunks. This is permanent and cannot be undone.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'knowledge_base_id' => $schema->string()
                ->description('UUID of the knowledge base to delete')
                ->required(),
        ];
    }

    public function handle(Request $request, DeleteKnowledgeBaseAction $action): Response
    {
        $request->validate(['knowledge_base_id' => 'required|string']);

        $kb = KnowledgeBase::withoutGlobalScopes()->find($request->get('knowledge_base_id'));

        if (! $kb) {
            return Response::text(json_encode(['error' => 'Knowledge base not found.']));
        }

        $action->execute($kb);

        return Response::text(json_encode(['message' => 'Knowledge base deleted.', 'id' => $request->get('knowledge_base_id')]));
    }
}
