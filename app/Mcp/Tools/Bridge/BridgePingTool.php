<?php

namespace App\Mcp\Tools\Bridge;

use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Bridge\Models\BridgeConnectionStatus;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class BridgePingTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'bridge_ping';

    protected string $description = 'Ping an HTTP-mode bridge connection to verify it is reachable. Updates connection status to connected or disconnected based on the result. Only available for HTTP-mode connections (not WebSocket relay connections).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_id' => $schema->string()
                ->description('UUID of the bridge connection to ping')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'connection_id' => 'required|uuid',
        ]);

        $teamId = app('mcp.team_id') ?? null;

        $connection = BridgeConnection::where('team_id', $teamId)
            ->find($validated['connection_id']);

        if (! $connection) {
            return $this->notFoundError('bridge connection');
        }

        if (! $connection->isHttpMode()) {
            return $this->failedPreconditionError('Ping is only available for HTTP-mode connections.');
        }

        $headers = [];
        if (! empty($connection->endpoint_secret)) {
            $headers['Authorization'] = 'Bearer '.$connection->endpoint_secret;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders($headers)
                ->get(rtrim($connection->endpoint_url, '/').'/health');

            $online = $response->successful();
            $connection->update([
                'status' => $online ? BridgeConnectionStatus::Connected : BridgeConnectionStatus::Disconnected,
                'last_seen_at' => $online ? now() : $connection->last_seen_at,
            ]);

            return Response::text(json_encode([
                'online' => $online,
                'status' => $connection->status->value,
                'endpoint_url' => $connection->endpoint_url,
                'last_seen_at' => $connection->last_seen_at?->toISOString(),
                'http_status' => $response->status(),
            ]));
        } catch (\Throwable $e) {
            $connection->update(['status' => BridgeConnectionStatus::Disconnected]);

            return Response::text(json_encode([
                'online' => false,
                'status' => BridgeConnectionStatus::Disconnected->value,
                'endpoint_url' => $connection->endpoint_url,
                'error' => $e->getMessage(),
            ]));
        }
    }
}
