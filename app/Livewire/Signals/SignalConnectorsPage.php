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
        'clearcue' => ['label' => 'ClearCue',  'category' => 'GTM Intent',    'icon' => null,        'domain' => 'clearcue.ai',     'env_key' => 'services.clearcue.webhook_secret', 'path' => '/api/signals/clearcue'],