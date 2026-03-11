<?php

namespace App\Livewire\Signals;

use App\Domain\Signal\Models\ConnectorSignalSubscription;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalConnectorSetting;
use App\Models\Connector;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Webklex\PHPIMAP\ClientManager;

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

    // IMAP connector form state
    public bool $showImapForm = false;

    public string $imapName = '';

    public string $imapHost = '';

    public int $imapPort = 993;

    public string $imapEncryption = 'ssl';

    public string $imapFolder = 'INBOX';

    public string $imapUsername = '';

    public string $imapPassword = '';

    public int $imapMaxPerPoll = 50;

    public string $imapTags = 'email';

    public ?string $editingImapId = null;

    public ?string $imapTestResult = null;

    public bool $imapTestOk = false;

    /**
     * Static webhook connector definitions.
     * Declared as private so Livewire does not try to hydrate/dehydrate it.
     */
    /** Drivers that also support per-subscription OAuth webhooks */
    private const SUBSCRIBABLE_DRIVERS = ['github', 'linear'];

    private array $webhookConnectors = [
        'github' => ['label' => 'GitHub',    'category' => 'Code & Issues', 'icon' => 'github',    'domain' => 'github.com',      'env_key' => 'services.github.webhook_secret',  'path' => '/api/signals/github'],
        'slack' => ['label' => 'Slack',     'category' => 'Chat',          'icon' => null,        'domain' => 'slack.com',       'env_key' => 'services.slack.signing_secret',   'path' => '/api/signals/slack'],
        'jira' => ['label' => 'Jira',      'category' => 'Issues',        'icon' => 'jira',      'domain' => 'atlassian.com',   'env_key' => 'services.jira.webhook_secret',    'path' => '/api/signals/jira'],
        'linear' => ['label' => 'Linear',    'category' => 'Issues',        'icon' => 'linear',    'domain' => 'linear.app',      'env_key' => 'services.linear.webhook_secret',  'path' => '/api/signals/linear'],
        'discord' => ['label' => 'Discord',   'category' => 'Chat',          'icon' => 'discord',   'domain' => 'discord.com',     'env_key' => 'services.discord.webhook_secret', 'path' => '/api/signals/discord'],
        'sentry' => ['label' => 'Sentry',    'category' => 'Errors',        'icon' => 'sentry',    'domain' => 'sentry.io',       'env_key' => 'services.sentry.webhook_secret',  'path' => '/api/signals/sentry'],
        'pagerduty' => ['label' => 'PagerDuty', 'category' => 'Incidents',     'icon' => 'pagerduty', 'domain' => 'pagerduty.com',   'env_key' => 'services.pagerduty.auth_token',   'path' => '/api/signals/pagerduty'],
        'datadog' => ['label' => 'Datadog',   'category' => 'Monitoring',    'icon' => 'datadog',   'domain' => 'datadoghq.com',   'env_key' => null,                              'path' => '/api/signals/datadog/{secret}'],
        'whatsapp' => ['label' => 'WhatsApp',  'category' => 'Chat',          'icon' => 'whatsapp',  'domain' => 'whatsapp.com',    'env_key' => 'services.whatsapp.app_secret',    'path' => '/api/signals/whatsapp'],
        'github' => ['label' => 'GitHub',    'category' => 'Code & Issues', 'icon' => 'github',    'domain' => 'github.com',      'env_key' => 'services.github.webhook_secret',  'path' => '/api/signals/github'],
        'slack' => ['label' => 'Slack',     'category' => 'Chat',          'icon' => null,        'domain' => 'slack.com',       'env_key' => 'services.slack.signing_secret',   'path' => '/api/signals/slack'],
        'jira' => ['label' => 'Jira',      'category' => 'Issues',        'icon' => 'jira',      'domain' => 'atlassian.com',   'env_key' => 'services.jira.webhook_secret',    'path' => '/api/signals/jira'],
        'linear' => ['label' => 'Linear',    'category' => 'Issues',        'icon' => 'linear',    'domain' => 'linear.app',      'env_key' => 'services.linear.webhook_secret',  'path' => '/api/signals/linear'],
        'discord' => ['label' => 'Discord',   'category' => 'Chat',          'icon' => 'discord',   'domain' => 'discord.com',     'env_key' => 'services.discord.webhook_secret', 'path' => '/api/signals/discord'],
        'sentry' => ['label' => 'Sentry',    'category' => 'Errors',        'icon' => 'sentry',    'domain' => 'sentry.io',       'env_key' => 'services.sentry.webhook_secret',  'path' => '/api/signals/sentry'],
        'pagerduty' => ['label' => 'PagerDuty', 'category' => 'Incidents',     'icon' => 'pagerduty', 'domain' => 'pagerduty.com',   'env_key' => 'services.pagerduty.auth_token',   'path' => '/api/signals/pagerduty'],
        'datadog' => ['label' => 'Datadog',   'category' => 'Monitoring',    'icon' => 'datadog',   'domain' => 'datadoghq.com',   'env_key' => null,                              'path' => '/api/signals/datadog/{secret}'],
        'whatsapp' => ['label' => 'WhatsApp',  'category' => 'Chat',          'icon' => 'whatsapp',  'domain' => 'whatsapp.com',    'env_key' => 'services.whatsapp.app_secret',    'path' => '/api/signals/whatsapp'],
        'clearcue' => ['label' => 'ClearCue',  'category' => 'GTM Intent',    'icon' => null,        'domain' => 'clearcue.ai',     'env_key' => 'services.clearcue.webhook_secret', 'path' => '/api/signals/clearcue'],
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

    /**
     * Add a new IMAP connector record.
     */
    public function addImapConnector(): void
    {
        $rules = [
            'imapHost' => 'required|string|max:253',
            'imapPort' => 'required|integer|min:1|max:65535',
            'imapEncryption' => 'required|in:ssl,tls,none',
            'imapFolder' => 'required|string|max:100',
            'imapUsername' => 'required|string|max:255',
            'imapPassword' => 'required|string|max:255',
            'imapMaxPerPoll' => 'required|integer|min:1|max:100',
        ];

        $this->validate($rules);

        Connector::create([
            'type' => 'input',
            'driver' => 'imap',
            'name' => $this->imapName ?: ($this->imapUsername.'@'.$this->imapHost),
            'status' => 'active',
            'config' => [
                'host' => $this->imapHost,
                'port' => $this->imapPort,
                'encryption' => $this->imapEncryption,
                'folder' => $this->imapFolder,
                'username' => $this->imapUsername,
                'password' => $this->imapPassword,
                'max_per_poll' => $this->imapMaxPerPoll,
                'tags' => array_filter(array_map('trim', explode(',', $this->imapTags))),
                'last_uid' => 0,
            ],
        ]);

        $this->reset([
            'showImapForm', 'editingImapId', 'imapName', 'imapHost', 'imapPort', 'imapEncryption',
            'imapFolder', 'imapUsername', 'imapPassword', 'imapMaxPerPoll', 'imapTags', 'imapTestResult', 'imapTestOk',
        ]);
        $this->imapPort = 993;
        $this->imapEncryption = 'ssl';
        $this->imapFolder = 'INBOX';
        $this->imapMaxPerPoll = 50;
        $this->imapTags = 'email';
    }

    /**
     * Populate form fields for editing an existing IMAP connector.
     */
    public function editImap(string $id): void
    {
        $connector = Connector::where('id', $id)->where('driver', 'imap')->firstOrFail();
        /** @var array<string, mixed> $config */
        $config = (array) $connector->config;
        $this->editingImapId = $id;
        $this->imapName = $connector->name;
        $this->imapHost = $config['host'] ?? '';
        $this->imapPort = (int) ($config['port'] ?? 993);
        $this->imapEncryption = $config['encryption'] ?? 'ssl';
        $this->imapFolder = $config['folder'] ?? 'INBOX';
        $this->imapUsername = $config['username'] ?? '';
        $this->imapPassword = '';  // Never echo password back
        $this->imapMaxPerPoll = (int) ($config['max_per_poll'] ?? 50);
        $this->imapTags = implode(', ', (array) ($config['tags'] ?? ['email']));
        $this->showImapForm = true;
        $this->imapTestResult = null;
    }

    /**
     * Update an existing IMAP connector.
     */
    public function updateImapConnector(): void
    {
        $this->validate([
            'imapHost' => 'required|string|max:253',
            'imapPort' => 'required|integer|min:1|max:65535',
            'imapEncryption' => 'required|in:ssl,tls,none',
            'imapFolder' => 'required|string|max:100',
            'imapUsername' => 'required|string|max:255',
            'imapMaxPerPoll' => 'required|integer|min:1|max:100',
        ]);

        $connector = Connector::where('id', $this->editingImapId)->where('driver', 'imap')->firstOrFail();

        /** @var array<string, mixed> $config */
        $config = (array) $connector->config;
        $config['host'] = $this->imapHost;
        $config['port'] = $this->imapPort;
        $config['encryption'] = $this->imapEncryption;
        $config['folder'] = $this->imapFolder;
        $config['username'] = $this->imapUsername;
        if (! empty($this->imapPassword)) {
            $config['password'] = $this->imapPassword;
        }
        $config['max_per_poll'] = $this->imapMaxPerPoll;
        $config['tags'] = array_filter(array_map('trim', explode(',', $this->imapTags)));

        $connector->update([
            'name' => $this->imapName ?: ($this->imapUsername.'@'.$this->imapHost),
            'config' => $config,
        ]);

        $this->reset([
            'showImapForm', 'editingImapId', 'imapName', 'imapHost', 'imapPort', 'imapEncryption',
            'imapFolder', 'imapUsername', 'imapPassword', 'imapMaxPerPoll', 'imapTags', 'imapTestResult', 'imapTestOk',
        ]);
        $this->imapPort = 993;
        $this->imapEncryption = 'ssl';
        $this->imapFolder = 'INBOX';
        $this->imapMaxPerPoll = 50;
        $this->imapTags = 'email';
    }

    /**
     * Remove an IMAP connector by ID.
     */
    public function removeImapConnector(string $id): void
    {
        Connector::where('id', $id)->where('driver', 'imap')->delete();
    }

    /**
     * Test IMAP connection with current form credentials.
     */
    public function testImapConnection(): void
    {
        $this->validate([
            'imapHost' => 'required|string',
            'imapPort' => 'required|integer|min:1|max:65535',
            'imapUsername' => 'required|string',
        ]);

        $password = $this->imapPassword;

        // For edits, fall back to stored password if no new one entered
        if (empty($password) && $this->editingImapId) {
            $stored = Connector::find($this->editingImapId);
            /** @var array<string, mixed> $storedConfig */
            $storedConfig = (array) ($stored?->config ?? []);
            $password = $storedConfig['password'] ?? '';
        }

        if (empty($password)) {
            $this->imapTestResult = 'Password is required for testing.';
            $this->imapTestOk = false;

            return;
        }

        try {
            $cm = new ClientManager;
            $client = $cm->make([
                'host' => $this->imapHost,
                'port' => $this->imapPort,
                'encryption' => $this->imapEncryption === 'none' ? false : $this->imapEncryption,
                'validate_cert' => true,
                'username' => $this->imapUsername,
                'password' => $password,
                'protocol' => 'imap',
            ]);
            $client->connect();
            $folder = $client->getFolder($this->imapFolder ?: 'INBOX');
            $count = $folder?->messages()->unseen()->count() ?? 0;
            $client->disconnect();
            $this->imapTestResult = "Connected successfully. {$count} unseen messages in ".($this->imapFolder ?: 'INBOX').'.';
            $this->imapTestOk = true;
        } catch (\Throwable $e) {
            $this->imapTestResult = 'Connection failed: '.$e->getMessage();
            $this->imapTestOk = false;
        }
    }

    public function render()
    {
        // Aggregate 30-day signal stats keyed by source_type.
        $signalStats = Signal::selectRaw('source_type, MAX(received_at) as last_received_at, COUNT(*) as total')
            ->where('received_at', '>=', now()->subDays(30))
            ->groupBy('source_type')
            ->get()
            ->keyBy('source_type');

        // Load per-team DB connector settings (keyed by driver) for status checks.
        $dbSettings = SignalConnectorSetting::where('is_active', true)
            ->get()
            ->keyBy('driver');

        // Build enriched card data for the webhook connector grid.
        // Subscription counts per driver (for subscribable drivers like github, linear)
        $subscriptionCounts = ConnectorSignalSubscription::selectRaw('driver, COUNT(*) as total')
            ->where('is_active', true)
            ->whereIn('driver', self::SUBSCRIBABLE_DRIVERS)
            ->groupBy('driver')
            ->get()
            ->pluck('total', 'driver');

        $cards = [];
        foreach ($this->webhookConnectors as $driver => $def) {
            $dbSetting = $dbSettings->get($driver);

            // Prefer DB-stored secret status; fall back to env/config for self-hosted deployments.
            $secretConfigured = $dbSetting !== null
                ? (bool) $dbSetting->webhook_secret
                : ($def['env_key'] ? (bool) config($def['env_key']) : false);

            // Use DB last_signal_at when available (avoids extra JOIN on signals).
            $stats = $signalStats->get($driver);
            $lastReceived = $dbSetting?->last_signal_at
                ?? ($stats?->last_received_at ? Carbon::parse($stats->last_received_at) : null);
            $totalSignals = $dbSetting?->signal_count
                ?? (int) ($stats?->total ?? 0);

            $status = match (true) {
                ! $secretConfigured && $totalSignals > 0 => 'unsecured',
                $secretConfigured && $lastReceived instanceof Carbon && $lastReceived->gt(now()->subHours(24)) => 'active',
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
                'subscription_count' => (int) ($subscriptionCounts->get($driver, 0)),
                'supports_subscriptions' => in_array($driver, self::SUBSCRIBABLE_DRIVERS, true),
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

        $imapConnectors = Connector::where('type', 'input')
            ->where('driver', 'imap')
            ->orderBy('created_at')
            ->get();

        $signalProtocolConnectors = Connector::where('type', 'input')
            ->where('driver', 'signal_protocol')
            ->orderBy('created_at')
            ->get();

        $matrixConnectors = Connector::where('type', 'input')
            ->where('driver', 'matrix')
            ->orderBy('created_at')
            ->get();

        $recentSignals = Signal::select([
            'id', 'source_type', 'source_identifier', 'received_at', 'score', 'tags', 'duplicate_count',
        ])
            ->latest('received_at')
            ->limit(100)
            ->get();

        $availableSourceTypes = $recentSignals->pluck('source_type')->unique()->sort()->values();

        return view('livewire.signals.signal-connectors-page', compact(
            'cards', 'httpMonitors', 'rssFeeds', 'imapConnectors', 'signalProtocolConnectors', 'matrixConnectors',
            'recentSignals', 'availableSourceTypes',
        ))->layout('layouts.app', ['header' => 'Signal Sources']);
    }
}
