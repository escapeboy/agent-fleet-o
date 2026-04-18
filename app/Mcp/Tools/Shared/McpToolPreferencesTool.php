<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Models\Team;
use App\Mcp\Attributes\AssistantTool;
use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class McpToolPreferencesTool extends Tool
{
    protected string $name = 'mcp_tool_preferences';

    protected string $description = 'Set MCP tool preferences for the current team. Choose a profile (essential, standard, full) or provide a custom list of enabled tools.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'profile' => $schema->string()
                ->description('Profile preset to apply: essential, standard, full. Mutually exclusive with enabled.')
                ->enum(['essential', 'standard', 'full']),
            'enabled' => $schema->array()
                ->description('Custom list of tool names to enable. Mutually exclusive with profile. Use mcp_tool_catalog action to see available tools.'),
        ];
    }

    /**
     * @return Generator<Response>
     */
    public function handle(Request $request): Generator
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $team = Team::find($teamId);

        if (! $team) {
            yield Response::error('Team not found.');

            return;
        }

        $profile = $request->get('profile');
        $enabled = $request->get('enabled');

        if ($profile && $enabled) {
            yield Response::error('Provide either profile or enabled, not both.');

            return;
        }

        if (! $profile && ! $enabled) {
            yield Response::error('Provide either profile (essential, standard, full) or enabled (array of tool names).');

            return;
        }

        $catalogTools = $this->getAllCatalogToolNames();

        if ($profile) {
            $validProfiles = array_keys(config('mcp_profiles', []));

            if (! in_array($profile, $validProfiles)) {
                yield Response::error("Invalid profile '{$profile}'. Valid: ".implode(', ', $validProfiles));

                return;
            }

            $mcpTools = $profile === 'full'
                ? null
                : ['profile' => $profile];
        } else {
            $invalid = array_diff($enabled, $catalogTools);

            if (! empty($invalid)) {
                yield Response::error('Unknown tool names: '.implode(', ', $invalid).'. Use mcp_tool_catalog action to see available tools.');

                return;
            }

            $mcpTools = ['enabled' => array_values($enabled)];
        }

        $settings = $team->settings ?? [];
        $settings['mcp_tools'] = $mcpTools;
        $team->update(['settings' => $settings]);

        // Clear request-scoped cache so shouldRegister() picks up changes immediately.
        $cacheKey = "mcp.team_mcp_settings.{$teamId}";

        if (app()->bound($cacheKey)) {
            app()->forgetInstance($cacheKey);
        }

        // Notify clients that the tool list has changed (MCP spec notifications/tools/list_changed).
        // Clients that support listChanged (Cursor, VS Code/Copilot) will re-fetch tools/list.
        // Clients that don't (Claude Desktop/Code) will silently ignore this.
        yield Response::notification('notifications/tools/list_changed');

        yield Response::text(json_encode([
            'success' => true,
            'mcp_tools' => $mcpTools,
            'hint' => 'Tool list updated. Clients supporting listChanged will auto-refresh.',
        ]));
    }

    private function getAllCatalogToolNames(): array
    {
        $catalog = config('mcp_tool_catalog.groups', []);
        $names = [];

        foreach ($catalog as $group) {
            foreach ($group['tools'] as $toolName => $description) {
                $names[] = $toolName;
            }
        }

        return $names;
    }
}
