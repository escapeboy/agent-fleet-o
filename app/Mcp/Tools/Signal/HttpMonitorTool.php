<?php

namespace App\Mcp\Tools\Signal;

use App\Mcp\Attributes\AssistantTool;
use App\Models\Connector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[AssistantTool('read')]
class HttpMonitorTool extends Tool
{
    protected string $name = 'http_monitor_manage';

    protected string $description = 'Manage HTTP/URL health monitors. List configured monitors, add a new URL to monitor for availability or content changes, and remove monitors.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: list | add | remove | status')
                ->enum(['list', 'add', 'remove', 'status'])
                ->required(),
            'url' => $schema->string()
                ->description('URL to monitor (required for add)'),
            'monitor_type' => $schema->string()
                ->description('Monitor type: availability | content_change | both (default: availability)')
                ->enum(['availability', 'content_change', 'both'])
                ->default('availability'),
            'name' => $schema->string()
                ->description('Human-readable name for the monitor (optional, defaults to hostname)'),
            'connector_id' => $schema->string()
                ->description('Connector UUID (required for remove)'),
            'expected_status' => $schema->array()
                ->description('Expected HTTP status codes (default: [200])'),
            'ssl_check' => $schema->boolean()
                ->description('Alert when SSL certificate expires within 14 days (default: true)')
                ->default(true),
        ];
    }

    public function handle(Request $request): Response
    {
        $action = $request->get('action');

        return match ($action) {
            'list' => $this->list(),
            'add' => $this->add($request),
            'remove' => $this->remove($request),
            'status' => $this->status(),
            default => Response::error("Unknown action: {$action}"),
        };
    }

    private function list(): Response
    {
        $monitors = Connector::where('type', 'input')
            ->where('driver', 'http_monitor')
            ->where('status', 'active')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'url' => $c->config['url'] ?? null,
                'monitor_type' => $c->config['monitor_type'] ?? 'availability',
                'last_status' => $c->config['last_status'] ?? null,
                'consecutive_failures' => $c->config['consecutive_failures'] ?? 0,
                'last_success_at' => $c->last_success_at?->toIso8601String(),
                'last_error_at' => $c->last_error_at?->toIso8601String(),
            ]);

        return Response::text(json_encode(['monitors' => $monitors->values()]));
    }

    private function add(Request $request): Response
    {
        $url = $request->get('url');

        if (! $url) {
            return Response::error('url is required for add action.');
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return Response::error("Invalid URL: {$url}");
        }

        $monitorType = $request->get('monitor_type', 'availability');
        $name = $request->get('name') ?? parse_url($url, PHP_URL_HOST) ?? $url;
        $expectedStatus = $request->get('expected_status') ?? [200];
        $sslCheck = $request->get('ssl_check', true);

        $connector = Connector::create([
            'type' => 'input',
            'driver' => 'http_monitor',
            'name' => $name,
            'status' => 'active',
            'config' => [
                'url' => $url,
                'monitor_type' => $monitorType,
                'expected_status' => $expectedStatus,
                'ssl_check' => $sslCheck,
                'timeout' => 15,
                'last_content_hash' => null,
                'last_etag' => null,
                'last_modified' => null,
                'last_status' => null,
                'consecutive_failures' => 0,
            ],
        ]);

        return Response::text(json_encode([
            'success' => true,
            'connector_id' => $connector->id,
            'name' => $name,
            'url' => $url,
            'monitor_type' => $monitorType,
            'message' => "Now monitoring {$url} for {$monitorType}. Polls every 5 minutes.",
        ]));
    }

    private function remove(Request $request): Response
    {
        $connectorId = $request->get('connector_id');

        if (! $connectorId) {
            return Response::error('connector_id is required for remove action.');
        }

        $connector = Connector::where('id', $connectorId)
            ->where('driver', 'http_monitor')
            ->first();

        if (! $connector) {
            return Response::error("HTTP monitor connector {$connectorId} not found.");
        }

        $url = $connector->config['url'] ?? 'unknown';
        $connector->delete();

        return Response::text(json_encode([
            'success' => true,
            'message' => "Stopped monitoring {$url}.",
        ]));
    }

    private function status(): Response
    {
        $count = Connector::where('type', 'input')
            ->where('driver', 'http_monitor')
            ->where('status', 'active')
            ->count();

        $failures = Connector::where('type', 'input')
            ->where('driver', 'http_monitor')
            ->where('status', 'active')
            ->whereRaw("(config->>'consecutive_failures')::int > 0")
            ->count();

        return Response::text(json_encode([
            'total_monitors' => $count,
            'monitors_with_failures' => $failures,
            'poll_interval' => 'every 5 minutes',
            'driver' => 'http_monitor',
        ]));
    }
}
