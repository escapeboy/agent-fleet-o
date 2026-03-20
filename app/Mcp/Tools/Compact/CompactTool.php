<?php

namespace App\Mcp\Tools\Compact;

use App\Domain\Shared\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Base class for compact/consolidated MCP tools.
 *
 * Each compact tool maps an "action" parameter to an original tool class,
 * delegating handle() calls with zero logic duplication. Schemas are
 * auto-merged from all child tools so clients discover every parameter.
 *
 * Supports per-team tool filtering via shouldRegister() — reads
 * team.settings['mcp_tools'] to determine which tools are visible.
 */
abstract class CompactTool extends Tool
{
    /**
     * Map of action name => original Tool class.
     *
     * @return array<string, class-string<Tool>>
     */
    abstract protected function toolMap(): array;

    /**
     * Filter tool visibility based on team MCP preferences.
     *
     * Called on every tools/list and tools/call request by the MCP framework.
     * Returns false to hide this tool from the client.
     *
     * Preference resolution:
     * - No team (stdio) → always visible
     * - No mcp_tools settings → all visible (backward compat)
     * - Profile mode → check against config/mcp_profiles.php presets
     * - Custom mode → check against explicit enabled list
     */
    public function shouldRegister(): bool
    {
        if (! app()->bound('mcp.team_id')) {
            return true;
        }

        $teamId = app('mcp.team_id');

        if (! $teamId) {
            return true;
        }

        $settings = static::resolveTeamMcpSettings($teamId);

        if ($settings === null) {
            return true;
        }

        $enabled = $settings['enabled'] ?? null;

        if ($enabled === null) {
            $profile = $settings['profile'] ?? 'full';
            $enabled = config("mcp_profiles.{$profile}");

            if ($enabled === null) {
                return true;
            }
        }

        return in_array($this->name(), $enabled);
    }

    /**
     * Resolve team MCP settings with request-scoped cache.
     *
     * Prevents N+1 queries when 33 tools each call shouldRegister().
     *
     * @return array{profile?: string, enabled?: list<string>}|null
     */
    protected static function resolveTeamMcpSettings(string $teamId): ?array
    {
        $cacheKey = "mcp.team_mcp_settings.{$teamId}";

        if (app()->bound($cacheKey)) {
            return app($cacheKey);
        }

        $team = Team::find($teamId);
        $settings = $team?->settings['mcp_tools'] ?? null;

        app()->instance($cacheKey, $settings);

        return $settings;
    }

    public function schema(JsonSchema $schema): array
    {
        $actions = array_keys($this->toolMap());

        $merged = [
            'action' => $schema->string()
                ->description('Action to perform: '.implode(', ', $actions))
                ->enum($actions)
                ->required(),
        ];

        // Auto-merge schemas from all child tools so clients discover every parameter.
        foreach ($this->toolMap() as $toolClass) {
            try {
                $childSchema = app($toolClass)->schema($schema);

                foreach ($childSchema as $key => $value) {
                    if (! isset($merged[$key])) {
                        $merged[$key] = $value;
                    }
                }
            } catch (\Throwable) {
                // Skip tools that can't be instantiated at schema time.
            }
        }

        return $merged;
    }

    public function handle(Request $request): Response
    {
        $action = $request->get('action');

        if (! $action) {
            $actions = array_keys($this->toolMap());

            return Response::error(
                "Missing required parameter 'action'. Valid actions: ".implode(', ', $actions),
            );
        }

        $map = $this->toolMap();

        if (! isset($map[$action])) {
            return Response::error(
                "Unknown action '{$action}'. Valid actions: ".implode(', ', array_keys($map)),
            );
        }

        return app($map[$action])->handle($request);
    }
}
