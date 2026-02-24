<?php

namespace App\Livewire\Signals;

use App\Domain\Signal\Models\Signal;
use App\Models\Connector;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Slide-over panel for per-connector setup guides.
 * Opened by the `open-connector-panel` browser event dispatched from SignalConnectorsPage.
 */
class ConnectorSetupPanel extends Component
{
    public bool $open = false;
    public string $driver = '';
    public string $connectorLabel = '';
    public string $connectorCategory = '';
    public string $webhookUrl = '';
    public bool $secretConfigured = false;
    public int $recentSignalCount = 0;
    public bool $checked = false;

    /**
     * Static connector definitions (private — not a Livewire property).
     */
    private array $definitions = [
        'github'    => ['label' => 'GitHub',    'category' => 'Code & Issues',  'env_key' => 'services.github.webhook_secret',  'path' => '/api/signals/github',    'env_var' => 'GITHUB_WEBHOOK_SECRET'],
        'slack'     => ['label' => 'Slack',     'category' => 'Chat',           'env_key' => 'services.slack.signing_secret',   'path' => '/api/signals/slack',     'env_var' => 'SLACK_SIGNING_SECRET'],
        'jira'      => ['label' => 'Jira',      'category' => 'Issues',         'env_key' => 'services.jira.webhook_secret',    'path' => '/api/signals/jira',      'env_var' => 'JIRA_WEBHOOK_SECRET'],
        'linear'    => ['label' => 'Linear',    'category' => 'Issues',         'env_key' => 'services.linear.webhook_secret',  'path' => '/api/signals/linear',    'env_var' => 'LINEAR_WEBHOOK_SECRET'],
        'discord'   => ['label' => 'Discord',   'category' => 'Chat',           'env_key' => 'services.discord.webhook_secret', 'path' => '/api/signals/discord',   'env_var' => 'DISCORD_WEBHOOK_SECRET'],
        'sentry'    => ['label' => 'Sentry',    'category' => 'Errors',         'env_key' => 'services.sentry.webhook_secret',  'path' => '/api/signals/sentry',    'env_var' => 'SENTRY_WEBHOOK_SECRET'],
        'pagerduty' => ['label' => 'PagerDuty', 'category' => 'Incidents',      'env_key' => 'services.pagerduty.auth_token',   'path' => '/api/signals/pagerduty', 'env_var' => 'PAGERDUTY_AUTH_TOKEN'],
        'datadog'   => ['label' => 'Datadog',   'category' => 'Monitoring',     'env_key' => null,                              'path' => '/api/signals/datadog/',  'env_var' => null],
        'whatsapp'  => ['label' => 'WhatsApp',  'category' => 'Chat',           'env_key' => 'services.whatsapp.app_secret',    'path' => '/api/signals/whatsapp',  'env_var' => 'WHATSAPP_APP_SECRET'],
    ];

    /**
     * Open the panel for the given driver.
     * For Datadog, generate/reuse a URL-embedded secret stored in a Connector record.
     */
    #[On('open-connector-panel')]
    public function open(string $driver): void
    {
        $def = $this->definitions[$driver] ?? null;
        if (! $def) {
            return;
        }

        $this->driver           = $driver;
        $this->connectorLabel   = $def['label'];
        $this->connectorCategory = $def['category'];
        $this->checked          = false;
        $this->recentSignalCount = 0;

        if ($driver === 'datadog') {
            // Datadog uses a URL-embedded secret; store/retrieve from a Connector record.
            $connector = Connector::firstOrCreate(
                ['type' => 'input', 'driver' => 'datadog'],
                ['name' => 'Datadog', 'status' => 'active', 'config' => ['secret' => Str::random(32)]]
            );
            $secret           = $connector->config['secret'] ?? Str::random(32);
            $this->webhookUrl = url('/api/signals/datadog/' . $secret);
            $this->secretConfigured = true;
        } else {
            $this->webhookUrl       = url($def['path']);
            $this->secretConfigured = $def['env_key'] ? (bool) config($def['env_key']) : false;
        }

        $this->open = true;
    }

    /**
     * Close the panel.
     */
    public function close(): void
    {
        $this->open = false;
    }

    /**
     * Check how many signals from this driver arrived in the last hour.
     */
    public function checkRecentEvents(): void
    {
        $this->recentSignalCount = Signal::where('source_type', $this->driver)
            ->where('received_at', '>=', now()->subHour())
            ->count();
        $this->checked = true;
    }

    public function render()
    {
        // Pass the env var name to the blade view (used in "Not configured" instructions).
        $envVar = $this->driver ? ($this->definitions[$this->driver]['env_var'] ?? null) : null;

        return view('livewire.signals.connector-setup-panel', compact('envVar'));
    }
}
