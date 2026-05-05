<?php

namespace App\Mcp\Tools\Bridge;

use App\Domain\Bridge\Models\BridgeConnection;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class BridgeUpdateUrlTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'bridge_update_url';

    protected string $description = 'Update the tunnel URL for an HTTP-mode bridge connection. Use when the tunnel URL changes (e.g., Cloudflare quick tunnels regenerate on daemon restart). Only applies to HTTP-mode connections.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_id' => $schema->string()
                ->description('UUID of the bridge connection to update')
                ->required(),
            'endpoint_url' => $schema->string()
                ->description('New public HTTPS tunnel URL for the bridge daemon')
                ->required(),
            'endpoint_secret' => $schema->string()
                ->description('New bearer token for Authorization header (omit to keep existing secret)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'connection_id' => 'required|uuid',
            'endpoint_url' => 'required|url|max:500',
            'endpoint_secret' => 'nullable|string|max:255',
        ]);

        $teamId = app('mcp.team_id') ?? null;

        $connection = BridgeConnection::where('team_id', $teamId)
            ->find($validated['connection_id']);

        if (! $connection) {
            return $this->notFoundError('bridge connection');
        }

        if (! $connection->isHttpMode()) {
            return $this->failedPreconditionError('URL updates are only available for HTTP-mode connections.');
        }

        $updates = ['endpoint_url' => rtrim($validated['endpoint_url'], '/')];

        if (array_key_exists('endpoint_secret', $validated)) {
            $updates['endpoint_secret'] = $validated['endpoint_secret'];
        }

        $connection->update($updates);

        return Response::text(json_encode([
            'success' => true,
            'id' => $connection->id,
            'endpoint_url' => $connection->endpoint_url,
            'tunnel_provider' => $connection->tunnel_provider,
            'label' => $connection->label,
            'status' => $connection->status->value,
        ]));
    }
}
