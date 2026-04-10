<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\AgentHook;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class AgentHookToggleTool extends Tool
{
    protected string $name = 'agent_hook_toggle';

    protected string $description = 'Enable or disable an agent lifecycle hook.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'hook_id' => $schema->string()
                ->description('Hook UUID')
                ->required(),
            'enabled' => $schema->boolean()
                ->description('Set to true to enable, false to disable')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $hook = AgentHook::where('team_id', auth()->user()->current_team_id)
            ->findOrFail($request->string('hook_id'));

        $hook->update(['enabled' => $request->boolean('enabled')]);

        $state = $hook->enabled ? 'enabled' : 'disabled';

        return Response::text("Hook '{$hook->name}' {$state}");
    }
}
