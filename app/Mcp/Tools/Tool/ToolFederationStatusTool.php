<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Agent\Models\Agent;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool as ToolModel;
use App\Domain\Tool\Models\ToolFederationGroup;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ToolFederationStatusTool extends Tool
{
    protected string $name = 'tool_federation_status';

    protected string $description = 'Show the tool federation configuration for a specific agent — whether federation is enabled, the active group, and the pool size.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('Agent ID to inspect'),
        ];
    }

    public function handle(Request $request): Response
    {
        $agent = Agent::findOrFail($request->get('agent_id'));

        $enabled = (bool) ($agent->config['use_tool_federation'] ?? false);
        $groupId = $agent->config['tool_federation_group_id'] ?? null;

        $group = $groupId ? ToolFederationGroup::find($groupId) : null;

        $poolCount = ToolModel::query()
            ->where('team_id', $agent->team_id)
            ->where('status', ToolStatus::Active)
            ->when($group && ! empty($group->tool_ids), fn ($q) => $q->whereIn('id', $group->tool_ids))
            ->count();

        return Response::text(json_encode([
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'federation_enabled' => $enabled,
            'federation_group' => $group ? ['id' => $group->id, 'name' => $group->name] : null,
            'pool_size' => $enabled ? $poolCount : null,
            'explicit_tool_count' => $agent->tools()->count(),
        ]));
    }
}
