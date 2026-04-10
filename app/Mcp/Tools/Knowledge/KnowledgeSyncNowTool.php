<?php

namespace App\Mcp\Tools\Knowledge;

use App\Domain\Memory\Models\Memory;
use App\Domain\Signal\Contracts\KnowledgeConnectorInterface;
use App\Domain\Signal\Services\SignalConnectorRegistry;
use App\Models\Connector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class KnowledgeSyncNowTool extends Tool
{
    protected string $name = 'knowledge_sync_now';

    protected string $description = 'Trigger an immediate knowledge sync for a specific connector binding. Calls the connector\'s poll() method synchronously. Returns the number of documents ingested.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'connector_id' => $schema->string()
                ->description('UUID of the active knowledge connector binding to sync')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $validated = $request->validate([
            'connector_id' => 'required|uuid',
        ]);

        $connector = Connector::withoutGlobalScopes()
            ->where('id', $validated['connector_id'])
            ->where('team_id', $teamId)
            ->where('type', 'input')
            ->where('status', 'active')
            ->first();

        if (! $connector) {
            return Response::text(json_encode([
                'success' => false,
                'error' => 'Connector not found or not active for this team.',
            ]));
        }

        $registry = app(SignalConnectorRegistry::class);

        if (! $registry->has($connector->driver)) {
            return Response::text(json_encode([
                'success' => false,
                'error' => "No connector registered for driver: {$connector->driver}",
            ]));
        }

        $instance = $registry->resolve($connector->driver);

        if (! ($instance instanceof KnowledgeConnectorInterface)) {
            return Response::text(json_encode([
                'success' => false,
                'error' => "Driver '{$connector->driver}' is not a knowledge connector.",
            ]));
        }

        try {
            $config = array_merge($connector->config ?? [], [
                'team_id' => $teamId,
                'binding_id' => $connector->id,
            ]);

            $before = Memory::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('source_type', $connector->driver)
                ->count();

            $instance->poll($config);

            $after = Memory::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('source_type', $connector->driver)
                ->count();

            $connector->update([
                'last_success_at' => now(),
                'last_error_message' => null,
            ]);

            return Response::text(json_encode([
                'success' => true,
                'connector_id' => $connector->id,
                'driver' => $connector->driver,
                'documents_ingested_approx' => max(0, $after - $before),
                'synced_at' => now()->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            $connector->update([
                'last_error_at' => now(),
                'last_error_message' => mb_substr($e->getMessage(), 0, 500),
            ]);

            return Response::text(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]));
        }
    }
}
