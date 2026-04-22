<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Agent\Models\Agent;
use App\Domain\Tool\Models\ToolFederationGroup;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class ToolFederationEnableTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'tool_federation_enable';

    protected string $description = 'Enable or disable tool federation for an agent. When enabled, the agent can access all active team tools dynamically.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('Agent ID to configure'),
            'enabled' => $schema->boolean()->description('true to enable federation, false to disable'),
            'group_id' => $schema->string()->description('Optional federation group ID to limit the pool. Omit to use all team tools.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $agent = Agent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($request->get('agent_id'));

        if (! $agent) {
            return $this->notFoundError('agent');
        }

        $enabled = (bool) $request->get('enabled', true);
        $groupId = $request->get('group_id');

        $config = $agent->config ?? [];
        $config['use_tool_federation'] = $enabled;

        if ($groupId !== null) {
            $group = ToolFederationGroup::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->find($groupId);
            $config['tool_federation_group_id'] = $group?->id;
        } elseif (! $enabled) {
            unset($config['tool_federation_group_id']);
        }

        $agent->update(['config' => $config]);

        return Response::text(json_encode([
            'success' => true,
            'agent_id' => $agent->id,
            'use_tool_federation' => $enabled,
            'tool_federation_group_id' => $config['tool_federation_group_id'] ?? null,
        ]));
    }
}
