<?php

namespace App\Mcp\Tools\Knowledge;

use App\Domain\Knowledge\Actions\CreateKnowledgeBaseAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class KnowledgeBaseCreateTool extends Tool
{
    protected string $name = 'knowledge_base_create';

    protected string $description = 'Create a new knowledge base. Optionally bind it to an agent so that agent automatically retrieves relevant context during execution.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Human-readable name for the knowledge base')
                ->required(),
            'description' => $schema->string()
                ->description('Optional description'),
            'agent_id' => $schema->string()
                ->description('UUID of the agent to bind this knowledge base to'),
        ];
    }

    public function handle(Request $request, CreateKnowledgeBaseAction $action): Response
    {
        $request->validate(['name' => 'required|string|max:255']);

        $teamId = auth()->user()?->current_team_id;

        $kb = $action->execute(
            teamId: $teamId,
            name: $request->get('name'),
            description: $request->get('description'),
            agentId: $request->get('agent_id'),
        );

        return Response::text(json_encode([
            'id' => $kb->id,
            'name' => $kb->name,
            'status' => $kb->status->value,
            'agent_id' => $kb->agent_id,
            'message' => 'Knowledge base created. Use knowledge_base_ingest to add documents.',
        ]));
    }
}
