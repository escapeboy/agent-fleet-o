<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class McpToolCatalogTool extends Tool
{
    protected string $name = 'mcp_tool_catalog';

    protected string $description = 'List all available MCP compact tools grouped by domain, with enabled/disabled status for the current team.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $catalog = config('mcp_tool_catalog.groups', []);
        $profiles = config('mcp_profiles', []);

        $enabledTools = null;
        $activeProfile = 'full';

        if ($teamId) {
            $team = Team::find($teamId);
            $mcpSettings = $team?->settings['mcp_tools'] ?? null;

            if ($mcpSettings) {
                $enabledTools = $mcpSettings['enabled'] ?? null;

                if ($enabledTools === null) {
                    $activeProfile = $mcpSettings['profile'] ?? 'full';
                    $enabledTools = $profiles[$activeProfile] ?? null;
                } else {
                    $activeProfile = 'custom';
                }
            }
        }

        $groups = [];

        foreach ($catalog as $groupKey => $group) {
            $tools = [];

            foreach ($group['tools'] as $toolName => $toolDescription) {
                $tools[$toolName] = [
                    'description' => $toolDescription,
                    'enabled' => $enabledTools === null || in_array($toolName, $enabledTools),
                ];
            }

            $groups[$groupKey] = [
                'label' => $group['label'],
                'description' => $group['description'],
                'tools' => $tools,
            ];
        }

        return Response::text(json_encode([
            'active_profile' => $activeProfile,
            'available_profiles' => array_keys($profiles),
            'groups' => $groups,
        ]));
    }
}
