<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Models\Signal;
use App\Mcp\Attributes\AssistantTool;
use App\Models\Connector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('read')]
class InboundConnectorManageTool extends Tool
{
    protected string $name = 'inbound_connector_manage';

    protected string $description = 'Manage inbound signal connectors. List all connector types with status, get setup instructions, and manage HTTP monitors and RSS feeds.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action to perform')
                ->enum(['list_connectors', 'get_setup_instructions', 'connector_status', 'add_monitor', 'remove_monitor', 'add_rss_feed', 'remove_rss_feed'])
                ->required(),
            'driver' => $schema->string()
                ->description('Connector driver name (for get_setup_instructions, connector_status)'),
            'url' => $schema->string()
                ->description('URL to monitor or RSS feed URL (for add_monitor, add_rss_feed)'),
            'name' => $schema->string()
                ->description('Human-readable name (optional)'),
            'monitor_type' => $schema->string()
                ->description('Monitor type: availability | content_change | both')
                ->enum(['availability', 'content_change', 'both']),
            'connector_id' => $schema->string()
                ->description('Connector UUID (for remove_monitor, remove_rss_feed)'),
        ];
    }

    public function handle(Request $request): Response
    {
        try {
            return match ($request->get('action')) {
                'list_connectors' => $this->listConnectors(),
                'get_setup_instructions' => $this->getSetupInstructions($request->get('driver')),
                'connector_status' => $this->connectorStatus($request->get('driver')),
                'add_monitor' => $this->addMonitor($request),
                'remove_monitor' => $this->removeMonitor($request->get('connector_id')),
                'add_rss_feed' => $this->addRssFeed($request),
                'remove_rss_feed' => $this->removeRssFeed($request->get('connector_id')),
                default => Response::error('Unknown action'),
            };
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * List all webhook connector types with 30-day signal stats, plus polling connectors.
     */
    private function listConnectors(): Response
    {
        $webhookDrivers = ['github', 'slack', 'jira', 'linear', 'discord', 'sentry', 'pagerduty', 'datadog', 'whatsapp'];

        $stats = Signal::selectRaw('source_type, MAX(received_at) as last_received_at, COUNT(*) as total')
            ->where('received_at', '>=', now()->subDays(30))
            ->groupBy('source_type')
            ->get()
            ->keyBy('source_type');

        $webhooks = array_map(fn ($d) => [
            'driver' => $d,
            'type' => 'webhook',
            'webhook_url' => url('/api/signals/'.($d === 'datadog' ? 'datadog/{secret}' : $d)),
            'signals_last_30d' => (int) ($stats->get($d)?->total ?? 0),
            'last_received_at' => $stats->get($d)?->last_received_at,
        ], $webhookDrivers);

        $pollingConnectors = Connector::where('type', 'input')
            ->whereIn('driver', ['rss', 'imap', 'http_monitor', 'signal_protocol', 'matrix'])
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'driver' => $c->driver,
                'name' => $c->name,
                'status' => $c->status,
                'last_success_at' => $c->last_success_at?->toIso8601String(),
                'last_error_message' => $c->last_error_message,
            ]);

        return Response::text(json_encode([
            'webhook_connectors' => array_values($webhooks),
            'polling_connectors' => $pollingConnectors->values(),
        ]));
    }

    /**
     * Return webhook URL and env var name for a given driver.
     */
    private function getSetupInstructions(?string $driver): Response
    {
        if (! $driver) {
            return Response::error('driver parameter required');
        }

        $envVars = [
            'github' => 'GITHUB_WEBHOOK_SECRET',
            'slack' => 'SLACK_SIGNING_SECRET',
            'jira' => 'JIRA_WEBHOOK_SECRET',
            'linear' => 'LINEAR_WEBHOOK_SECRET',
            'discord' => 'DISCORD_WEBHOOK_SECRET',
            'sentry' => 'SENTRY_WEBHOOK_SECRET',
            'pagerduty' => 'PAGERDUTY_AUTH_TOKEN',
        ];

        $pollingInstructions = [
            'signal_protocol' => [
                'type' => 'polling',
                'sidecar' => 'bbernhard/signal-cli-rest-api',
                'config_keys' => ['api_url', 'phone_number', 'team_id'],
                'docker_image' => 'bbernhard/signal-cli-rest-api:latest',
                'poll_interval' => 'every minute',
                'notes' => 'Run signal-cli-rest-api as a Docker sidecar. Register your Signal number first using the sidecar API.',
            ],
            'matrix' => [
                'type' => 'polling',
                'config_keys' => ['homeserver_url', 'access_token', 'bot_user_id', 'room_ids', 'team_id'],
                'poll_interval' => 'every minute',
                'notes' => 'Create a Matrix bot account on your homeserver. Use /_matrix/client/v3/login to obtain an access_token.',
            ],
        ];

        if (isset($pollingInstructions[$driver])) {
            return Response::text(json_encode(array_merge(['driver' => $driver], $pollingInstructions[$driver])));
        }

        $knownDrivers = array_keys($envVars) + ['datadog', 'whatsapp'];
        if (! in_array($driver, $knownDrivers, true)) {
            return Response::error("Unknown driver: {$driver}");
        }

        return Response::text(json_encode([
            'driver' => $driver,
            'webhook_url' => url('/api/signals/'.$driver),
            'env_var' => $envVars[$driver] ?? null,
        ]));
    }

    /**
     * Return recent signal counts and last received timestamp for a driver.
     */
    private function connectorStatus(?string $driver): Response
    {
        if (! $driver) {
            return Response::error('driver parameter required');
        }

        $recentCount = Signal::where('source_type', $driver)
            ->where('received_at', '>=', now()->subHour())
            ->count();

        $last = Signal::where('source_type', $driver)
            ->orderByDesc('received_at')
            ->first();

        return Response::text(json_encode([
            'driver' => $driver,
            'signals_last_hour' => $recentCount,
            'last_received_at' => $last?->received_at?->toIso8601String(),
            'total_all_time' => Signal::where('source_type', $driver)->count(),
        ]));
    }

    /**
     * Create a new HTTP Monitor connector.
     */
    private function addMonitor(Request $request): Response
    {
        $url = $request->get('url');
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return Response::error('Valid url is required');
        }
        if (! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'])) {
            return Response::error('URL must use http or https scheme');
        }

        $connector = Connector::create([
            'type' => 'input',
            'driver' => 'http_monitor',
            'name' => $request->get('name') ?? (parse_url($url, PHP_URL_HOST) ?? $url),
            'status' => 'active',
            'config' => [
                'url' => $url,
                'monitor_type' => $request->get('monitor_type', 'availability'),
                'expected_status' => [200],
                'ssl_check' => true,
                'timeout' => 15,
                'last_status' => null,
                'consecutive_failures' => 0,
            ],
        ]);

        return Response::text(json_encode(['success' => true, 'connector_id' => $connector->id]));
    }

    /**
     * Delete an HTTP Monitor connector by UUID.
     */
    private function removeMonitor(?string $id): Response
    {
        if (! $id) {
            return Response::error('connector_id required');
        }

        $connector = Connector::where('id', $id)->where('driver', 'http_monitor')->first();
        if (! $connector) {
            return Response::error("HTTP monitor {$id} not found");
        }

        $url = $connector->config['url'] ?? 'unknown';
        $connector->delete();

        return Response::text(json_encode(['success' => true, 'message' => "Stopped monitoring {$url}"]));
    }

    /**
     * Create a new RSS feed connector.
     */
    private function addRssFeed(Request $request): Response
    {
        $url = $request->get('url');
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return Response::error('Valid url is required');
        }

        $connector = Connector::create([
            'type' => 'input',
            'driver' => 'rss',
            'name' => $request->get('name') ?? (parse_url($url, PHP_URL_HOST) ?? $url),
            'status' => 'active',
            'config' => ['url' => $url, 'tags' => []],
        ]);

        return Response::text(json_encode([
            'success' => true,
            'connector_id' => $connector->id,
            'message' => 'RSS feed added. Polls every 15 minutes.',
        ]));
    }

    /**
     * Delete an RSS feed connector by UUID.
     */
    private function removeRssFeed(?string $id): Response
    {
        if (! $id) {
            return Response::error('connector_id required');
        }

        $connector = Connector::where('id', $id)->where('driver', 'rss')->first();
        if (! $connector) {
            return Response::error("RSS feed {$id} not found");
        }

        $connector->delete();

        return Response::text(json_encode(['success' => true]));
    }
}
