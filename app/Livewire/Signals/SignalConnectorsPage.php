<?php

namespace App\Livewire\Signals;

use App\Domain\Signal\Models\Signal;
use App\Models\Connector;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Signal Sources page — lists all inbound connector types with status,
 * and provides inline CRUD for HTTP monitors and RSS feeds.
 */
class SignalConnectorsPage extends Component
{
    // HTTP Monitor form state
    public bool $showAddMonitor = false;

    public string $newMonitorUrl = '';

    public string $newMonitorType = 'availability';

    public string $newMonitorName = '';

    // RSS feed form state
    public bool $showAddRss = false;

    public string $newRssUrl = '';

    public string $newRssName = '';

    /**
     * Static webhook connector definitions.
     * Declared as private so Livewire does not try to hydrate/dehydrate it.
     */
    private array $webhookConnectors = [
        'github' => ['label' => 'GitHub',    'category' => 'Code & Issues',  'env_key' => 'services.github.webhook_secret',  'path' => '/api/signals/github'],
        'slack' => ['label' => 'Slack',     'category' => 'Chat',           'env_key' => 'services.slack.signing_secret',   'path' => '/api/signals/slack'],
        'jira' => ['label' => 'Jira',      'category' => 'Issues',         'env_key' => 'services.jira.webhook_secret',    'path' => '/api/signals/jira'],
        'linear' => ['label' => 'Linear',    'category' => 'Issues',         'env_key' => 'services.linear.webhook_secret',  'path' => '/api/signals/linear'],
        'discord' => ['label' => 'Discord',   'category' => 'Chat',           'env_key' => 'services.discord.webhook_secret', 'path' => '/api/signals/discord'],
        'sentry' => ['label' => 'Sentry',    'category' => 'Errors',         'env_key' => 'services.sentry.webhook_secret',  'path' => '/api/signals/sentry'],
        'pagerduty' => ['label' => 'PagerDuty', 'category' => 'Incidents',      'env_key' => 'services.pagerduty.auth_token',   'path' => '/api/signals/pagerduty'],
        'datadog' => ['label' => 'Datadog',   'category' => 'Monitoring',     'env_key' => null,                              'path' => '/api/signals/datadog/{secret}'],
        'whatsapp' => ['label' => 'WhatsApp',  'category' => 'Chat',           'env_key' => 'services.whatsapp.app_secret',    'path' => '/api/signals/whatsapp'],
    ];

    public function mount(): void
    {
        // Gate-protect: only admin/owner may manage connector configuration.
        if (Gate::has('manage-team')) {
            Gate::authorize('manage-team');
        }
    }

    /**
     * Dispatch event to open the per-driver setup slide-over panel.
     */
    public function openSetupPanel(string $driver): void
    {
        $this->dispatch('open-connector-panel', driver: $driver);
    }

    /**
     * Add a new HTTP Monitor connector record.
     */
    public function addMonitor(): void
    {
        $this->validate([
            'newMonitorUrl' => ['required', 'url', function ($attr, $val, $fail) {
                if (! in_array(parse_url($val, PHP_URL_SCHEME), ['http', 'https'])) {
                    $fail('URL must start with http:// or https://');
                }
            }],
        ]);

        Connector::create([
            'type' => 'input',
            'driver' => 'http_monitor',
            'name' => $this->newMonitorName ?: (parse_url($this->newMonitorUrl, PHP_URL_HOST) ?? $this->newMonitorUrl),
            'status' => 'active',
            'config' => [
                'url' => $this->newMonitorUrl,
                'monitor_type' => $this->newMonitorType,
                'expected_status' => [200],
                'ssl_check' => true,
                'timeout' => 15,
                'last_content_hash' => null,
                'last_etag' => null,
                'last_modified' => null,
                'last_status' => null,
                'consecutive_failures' => 0,
            ],
        ]);

        $this->reset(['showAddMonitor', 'newMonitorUrl', 'newMonitorType', 'newMonitorName']);
    }

    /**
     * Remove an HTTP Monitor connector by ID.
     */
    public function removeMonitor(string $id): void
    {
        Connector::where('id', $id)->where('driver', 'http_monitor')->delete();
    }

    /**
     * Add a new RSS feed connector record.
     */
    public function addRssFeed(): void
    {
        $this->validate(['newRssUrl' => 'required|url']);

        Connector::create([
            'type' => 'input',
            'driver' => 'rss',
            'name' => $this->newRssName ?: (parse_url($this->newRssUrl, PHP_URL_HOST) ?? $this->newRssUrl),
            'status' => 'active',
            'config' => ['url' => $this->newRssUrl, 'tags' => []],
        ]);

        $this->reset(['showAddRss', 'newRssUrl', 'newRssName']);
    }

    /**
     * Remove an RSS feed connector by ID.
     */
    public function removeRssFeed(string $id): void
    {
        Connector::where('id', $id)->where('driver', 'rss')->delete();
    }

    public function render()
    {
        // Aggregate 30-day signal stats keyed by source_type.
        $signalStats = Signal::selectRaw('source_type, MAX(received_at) as last_received_at, COUNT(*) as total')
            ->where('received_at', '>=', now()->subDays(30))
            ->groupBy('source_type')
            ->get()
            ->keyBy('source_type');

        // Build enriched card data for the webhook connector grid.
        $cards = [];
        foreach ($this->webhookConnectors as $driver => $def) {
            $secretConfigured = $def['env_key'] ? (bool) config($def['env_key']) : false;
            $stats = $signalStats->get($driver);
            $lastReceived = $stats?->last_received_at ? Carbon::parse($stats->last_received_at) : null;
            $totalSignals = (int) ($stats?->total ?? 0);

            // Datadog uses a URL-embedded secret stored in a Connector record instead of an env var.
            if ($driver === 'datadog') {
                $ddConnector = Connector::where('driver', 'datadog')->where('type', 'input')->first();
                $secretConfigured = $ddConnector !== null;
            }

            $status = match (true) {
                ! $secretConfigured && $totalSignals > 0 => 'unsecured',
                $secretConfigured && $lastReceived?->gt(now()->subHours(24)) => 'active',
                $secretConfigured && $totalSignals > 0 => 'stale',
                $secretConfigured => 'configured',
                default => 'not_configured',
            };

            $cards[$driver] = [
                ...$def,
                'driver' => $driver,
                'status' => $status,
                'last_received_at' => $lastReceived,
                'total_signals' => $totalSignals,
                'secret_configured' => $secretConfigured,
            ];
        }

        $httpMonitors = Connector::where('type', 'input')
            ->where('driver', 'http_monitor')
            ->where('status', 'active')
            ->orderBy('created_at')
            ->get();

        $rssFeeds = Connector::where('type', 'input')
            ->where('driver', 'rss')
            ->orderBy('created_at')
            ->get();

        $imapConnector = Connector::where('type', 'input')
            ->where('driver', 'imap')
            ->first();

        return view('livewire.signals.signal-connectors-page', compact(
            'cards', 'httpMonitors', 'rssFeeds', 'imapConnector',
        ))->title('Signal Sources');
    }
}
