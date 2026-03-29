<?php

namespace App\Mcp\Tools\Knowledge;

use App\Domain\Signal\Contracts\KnowledgeConnectorInterface;
use App\Domain\Signal\Services\SignalConnectorRegistry;
use App\Models\Connector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class KnowledgeListSourcesTool extends Tool
{
    protected string $name = 'knowledge_list_sources';

    protected string $description = 'List all active knowledge connector bindings for the team (Notion, Confluence, GitHub Wiki). Returns connector name, driver, and last sync time.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $registry = app(SignalConnectorRegistry::class);

        $knowledgeDrivers = [];
        foreach ($registry->all() as $driver => $connector) {
            if ($connector instanceof KnowledgeConnectorInterface) {
                $knowledgeDrivers[] = $driver;
            }
        }

        $connectors = Connector::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('type', 'input')
            ->whereIn('driver', $knowledgeDrivers)
            ->where('status', 'active')
            ->get();

        $result = $connectors->map(function ($connector) use ($registry) {
            $lastSyncAt = null;

            try {
                $instance = $registry->resolve($connector->driver);
                if ($instance instanceof KnowledgeConnectorInterface) {
                    $bindingId = $connector->config['binding_id'] ?? $connector->id;
                    $lastSyncAt = $instance->getLastSyncAt($bindingId)?->toIso8601String();
                }
            } catch (\Throwable) {
                // Silently skip if connector resolution fails
            }

            return [
                'id' => $connector->id,
                'name' => $connector->name,
                'driver' => $connector->driver,
                'status' => $connector->status,
                'last_sync_at' => $lastSyncAt,
                'last_success_at' => $connector->last_success_at?->toIso8601String(),
                'last_error_message' => $connector->last_error_message,
            ];
        });

        return Response::text(json_encode([
            'knowledge_drivers' => $knowledgeDrivers,
            'active_connectors' => $result->toArray(),
            'count' => $result->count(),
        ]));
    }
}
