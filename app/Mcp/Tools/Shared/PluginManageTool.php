<?php

namespace App\Mcp\Tools\Shared;

use App\Contracts\HasHealthCheck;
use App\Domain\Shared\Models\PluginState;
use App\Domain\Shared\Services\PluginRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class PluginManageTool extends Tool
{
    protected string $name = 'plugin_manage';

    protected string $description = 'List and toggle installed FleetQ plugins. Operations: list, enable, disable.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()
                ->description('list: show all plugins with status | enable: activate a plugin | disable: deactivate a plugin')
                ->enum(['list', 'enable', 'disable'])
                ->required(),
            'plugin_id' => $schema->string()
                ->description('Plugin ID — required for enable/disable operations.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $operation = $request->get('operation');

        return match ($operation) {
            'list' => $this->listPlugins(),
            'enable' => $this->setEnabled($request, true),
            'disable' => $this->setEnabled($request, false),
            default => Response::error("Unknown operation '{$operation}'. Valid: list, enable, disable"),
        };
    }

    private function listPlugins(): Response
    {
        $registry = app(PluginRegistry::class);
        $plugins = $registry->all();

        $rows = $plugins->map(function ($plugin) {
            $state = PluginState::where('plugin_id', $plugin->getId())->first();

            $health = null;
            if ($plugin instanceof HasHealthCheck) {
                try {
                    $health = $plugin->check();
                } catch (\Throwable) {
                    $health = ['status' => 'error'];
                }
            }

            return [
                'id' => $plugin->getId(),
                'name' => $plugin->getName(),
                'version' => $state?->version ?? $plugin->getVersion(),
                'enabled' => $state?->enabled ?? true,
                'installed_at' => $state?->installed_at?->toIso8601String(),
                'health' => $health,
            ];
        })->values();

        return Response::text(json_encode([
            'count' => $rows->count(),
            'plugins' => $rows,
        ]));
    }

    private function setEnabled(Request $request, bool $enabled): Response
    {
        $pluginId = $request->get('plugin_id');

        if (empty($pluginId)) {
            return Response::error('plugin_id is required.');
        }

        $registry = app(PluginRegistry::class);
        $plugin = $registry->find($pluginId);

        if (! $plugin) {
            return Response::error("Plugin '{$pluginId}' not found.");
        }

        $state = PluginState::where('plugin_id', $pluginId)->first();

        if (! $state) {
            return Response::error("Plugin '{$pluginId}' has no state record. It may not be installed.");
        }

        $state->update(['enabled' => $enabled]);

        $action = $enabled ? 'enabled' : 'disabled';

        return Response::text(json_encode([
            'success' => true,
            'plugin_id' => $pluginId,
            'enabled' => $enabled,
            'message' => "Plugin '{$pluginId}' {$action}.",
        ]));
    }
}
