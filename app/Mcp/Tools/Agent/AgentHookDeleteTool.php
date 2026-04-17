<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\AgentHook;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class AgentHookDeleteTool extends Tool
{
    protected string $name = 'agent_hook_delete';

    protected string $description = 'Delete an agent lifecycle hook permanently.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'hook_id' => $schema->string()
                ->description('Hook UUID to delete')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $hook = AgentHook::where('team_id', auth()->user()->current_team_id)
            ->findOrFail($request->string('hook_id'));

        $name = $hook->name;
        $hook->delete();

        return Response::text("Hook '{$name}' deleted");
    }
}
