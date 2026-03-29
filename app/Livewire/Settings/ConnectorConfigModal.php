<?php

namespace App\Livewire\Settings;

use App\Domain\Outbound\Models\OutboundConnectorConfig;
use App\Domain\Outbound\Services\OutboundCredentialResolver;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\On;
use Livewire\Component;

class ConnectorConfigModal extends Component
{
    public bool $showModal = false;

    public string $channel = '';

    public string $channelLabel = '';

    // Dynamic form fields
    public string $botToken = '';

    public string $webhookUrl = '';

    public string $phoneNumberId = '';

    public string $accessToken = '';

    public string $smtpHost = '';

    public int $smtpPort = 587;

    public string $smtpUsername = '';

    public string $smtpPassword = '';

    public string $smtpEncryption = 'tls';

    public string $fromAddress = '';

    public string $fromName = '';

    public string $defaultUrl = '';

    public string $secret = '';

    // Ntfy-specific fields
    public string $ntfyBaseUrl = '';

    public string $ntfyTopic = '';

    public string $ntfyPriority = 'default';

    public string $ntfyTags = '';

    public string $ntfyToken = '';

    // Test state
    public ?string $testResult = null;

    public ?string $testError = null;

    public bool $testing = false;

    public bool $hasExistingConfig = false;

    private const CHANNEL_LABELS = [
        'telegram' => 'Telegram',
        'slack' => 'Slack',
        'discord' => 'Discord',
        'teams' => 'Microsoft Teams',
        'google_chat' => 'Google Chat',
        'whatsapp' => 'WhatsApp',
        'email' => 'Email (SMTP)',
        'webhook' => 'Webhook',
        'ntfy' => 'Ntfy',
    ];

    private const CHANNEL_DESCRIPTIONS = [
        'telegram' => 'Send messages via Telegram Bot API. Get your bot token from @BotFather.',
        'slack' => 'Send messages via Slack incoming webhook URL.',
        'discord' => 'Send messages via Discord webhook URL.',
        'teams' => 'Send messages via Microsoft Teams Power Automate webhook.',
        'google_chat' => 'Send messages via Google Chat space webhook.',
        'whatsapp' => 'Send messages via Meta WhatsApp Cloud API.',
        'email' => 'Send emails via SMTP. Configure your mail server credentials.',
        'webhook' => 'Send data via generic HTTP POST webhook.',
        'ntfy' => 'Send push notifications via ntfy (ntfy.sh or self-hosted). Lightweight and open-source.',
    ];

    #[On('openModal')]
    public function openModal(string $channel): void
    {
        $this->channel = $channel;
        $this->channelLabel = self::CHANNEL_LABELS[$channel] ?? $channel;
        $this->testResult = null;
        $this->testError = null;
        $this->testing = false;

        // Load existing DB config
        $resolver = app(OutboundCredentialResolver::class);
        $config = $resolver->getDbConfig($channel);

        $this->hasExistingConfig = $config !== null;

        if ($config) {
            $creds = $config->credentials ?? [];
            $this->loadCredentials($channel, $creds);
        } else {
            $this->resetFields();
        }

        $this->showModal = true;
    }

    public function save(): void
    {
        $credentials = $this->collectCredentials();

        if (empty(array_filter($credentials, fn ($v) => $v !== null && $v !== ''))) {
            $this->addError('credentials', 'At least one field is required.');

            return;
        }

        $teamId = $this->resolveTeamId();

        $config = OutboundConnectorConfig::withoutGlobalScopes()->updateOrCreate(
            ['team_id' => $teamId, 'channel' => $this->channel],
            ['credentials' => $credentials, 'is_active' => true],
        );

        $this->hasExistingConfig = true;
        $this->showModal = false;

        $this->dispatch('connector-saved');
        session()->flash('message', self::CHANNEL_LABELS[$this->channel].' connector saved.');
    }

    public function testConnection(): void
    {
        $this->testing = true;
        $this->testResult = null;
        $this->testError = null;

        try {
            $result = match ($this->channel) {
                'telegram' => $this->testTelegram(),
                'slack' => $this->testSlack(),
                'discord' => $this->testDiscord(),
                'teams' => $this->testTeams(),
                'google_chat' => $this->testGoogleChat(),
                'whatsapp' => $this->testWhatsApp(),
                'email' => $this->testSmtp(),
                'webhook' => $this->testWebhook(),
                'ntfy' => $this->testNtfy(),
                default => throw new \RuntimeException('Unknown channel'),
            };
            $this->testResult = $result;

            // Update DB record with test status
            $this->updateTestStatus('success');
        } catch (\Throwable $e) {
            $this->testError = $e->getMessage();
            $this->updateTestStatus($e->getMessage());
        } finally {
            $this->testing = false;
        }
    }

    public function disconnect(): void
    {
        $teamId = $this->resolveTeamId();

        OutboundConnectorConfig::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('channel', $this->channel)
            ->delete();

        $this->hasExistingConfig = false;
        $this->resetFields();
        $this->showModal = false;

        $this->dispatch('connector-saved');
        session()->flash('message', self::CHANNEL_LABELS[$this->channel].' connector removed. Channel is now inactive.');
    }

    public function render()
    {
        return view('livewire.settings.connector-config-modal', [
            'description' => self::CHANNEL_DESCRIPTIONS[$this->channel] ?? '',
        ]);
    }

    // ── Test methods ──

    private function testTelegram(): string
    {
        if (! $this->botToken) {
            throw new \RuntimeException('Bot token is required');
        }

        $response = Http::timeout(10)->get("https://api.telegram.org/bot{$this->botToken}/getMe");

        if ($response->successful() && $response->json('ok')) {
            return 'Connected as @'.$response->json('result.username');
        }

        throw new \RuntimeException($response->json('description', 'Invalid bot token'));
    }

    private function testSlack(): string
    {
        if (! $this->webhookUrl) {
            throw new \RuntimeException('Webhook URL is required');
        }

        $response = Http::timeout(10)->post($this->webhookUrl, [
            'text' => '[Test] FleetQ connectivity check',
        ]);

        if ($response->successful()) {
            return 'Message sent successfully';
        }

        throw new \RuntimeException('Slack returned '.$response->status().': '.$response->body());
    }

    private function testDiscord(): string
    {
        if (! $this->webhookUrl) {
            throw new \RuntimeException('Webhook URL is required');
        }

        $response = Http::timeout(10)->post($this->webhookUrl.'?wait=true', [
            'content' => '[Test] FleetQ connectivity check',
        ]);

        if ($response->successful()) {
            return 'Message sent successfully';
        }

        throw new \RuntimeException('Discord returned '.$response->status().': '.substr($response->body(), 0, 200));
    }

    private function testTeams(): string
    {
        if (! $this->webhookUrl) {
            throw new \RuntimeException('Webhook URL is required');
        }

        $payload = [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => [[
                        'type' => 'TextBlock',
                        'text' => '[Test] FleetQ connectivity check',
                        'wrap' => true,
                    ]],
                ],
            ]],
        ];

        $response = Http::timeout(10)->post($this->webhookUrl, $payload);

        if ($response->successful()) {
            return 'Card sent successfully';
        }

        throw new \RuntimeException('Teams returned '.$response->status());
    }

    private function testGoogleChat(): string
    {
        if (! $this->webhookUrl) {
            throw new \RuntimeException('Webhook URL is required');
        }

        $response = Http::timeout(10)->post($this->webhookUrl, [
            'text' => '[Test] FleetQ connectivity check',
        ]);

        if ($response->successful()) {
            return 'Message sent successfully';
        }

        throw new \RuntimeException('Google Chat returned '.$response->status());
    }

    private function testWhatsApp(): string
    {
        if (! $this->phoneNumberId || ! $this->accessToken) {
            throw new \RuntimeException('Phone Number ID and Access Token are required');
        }

        $response = Http::timeout(10)
            ->withToken($this->accessToken)
            ->get("https://graph.facebook.com/v21.0/{$this->phoneNumberId}");

        if ($response->successful()) {
            $name = $response->json('verified_name') ?? $response->json('display_phone_number') ?? 'verified';

            return "Connected: {$name}";
        }

        throw new \RuntimeException($response->json('error.message', 'Invalid credentials'));
    }

    private function testSmtp(): string
    {
        if (! $this->smtpHost) {
            throw new \RuntimeException('SMTP host is required');
        }

        $errno = 0;
        $errstr = '';
        $scheme = $this->smtpEncryption === 'ssl' ? 'ssl://' : '';
        $fp = @fsockopen($scheme.$this->smtpHost, $this->smtpPort, $errno, $errstr, 10);

        if (! $fp) {
            throw new \RuntimeException("Cannot connect to {$this->smtpHost}:{$this->smtpPort} - {$errstr}");
        }

        $banner = fgets($fp, 512);
        fclose($fp);

        if (str_starts_with(trim($banner), '220')) {
            return 'SMTP server responded: '.trim(substr($banner, 4));
        }

        throw new \RuntimeException("Unexpected SMTP response: {$banner}");
    }

    private function testWebhook(): string
    {
        if (! $this->defaultUrl) {
            throw new \RuntimeException('Webhook URL is required');
        }

        $payload = ['test' => true, 'source' => 'fleetq', 'timestamp' => now()->toIso8601String()];
        $headers = [];

        if ($this->secret) {
            $headers['X-Webhook-Signature'] = hash_hmac('sha256', json_encode($payload), $this->secret);
        }

        $response = Http::timeout(10)->withHeaders($headers)->post($this->defaultUrl, $payload);

        if ($response->successful()) {
            return 'Webhook responded with '.$response->status();
        }

        throw new \RuntimeException('Webhook returned '.$response->status().': '.substr($response->body(), 0, 200));
    }

    private function testNtfy(): string
    {
        if (! $this->ntfyBaseUrl) {
            throw new \RuntimeException('Base URL is required');
        }

        if (! $this->ntfyTopic) {
            throw new \RuntimeException('Topic is required');
        }

        $url = rtrim($this->ntfyBaseUrl, '/').'/'.$this->ntfyTopic;

        $headers = ['Priority' => $this->ntfyPriority ?: 'default', 'Title' => '[Test] FleetQ connectivity check'];

        if ($this->ntfyToken) {
            $headers['Authorization'] = 'Bearer '.$this->ntfyToken;
        }

        $response = Http::timeout(10)
            ->withHeaders($headers)
            ->withBody('[Test] FleetQ connectivity check', 'text/plain')
            ->post($url);

        if ($response->successful()) {
            return 'Notification sent to topic "'.$this->ntfyTopic.'"';
        }

        throw new \RuntimeException('Ntfy returned '.$response->status().': '.substr($response->body(), 0, 200));
    }

    // ── Helpers ──

    private function loadCredentials(string $channel, array $creds): void
    {
        $this->resetFields();

        match ($channel) {
            'telegram' => $this->botToken = $creds['bot_token'] ?? '',
            'slack' => $this->webhookUrl = $creds['webhook_url'] ?? '',
            'discord' => $this->webhookUrl = $creds['webhook_url'] ?? '',
            'teams' => $this->webhookUrl = $creds['webhook_url'] ?? '',
            'google_chat' => $this->webhookUrl = $creds['webhook_url'] ?? '',
            'whatsapp' => (function () use ($creds) {
                $this->phoneNumberId = $creds['phone_number_id'] ?? '';
                $this->accessToken = $creds['access_token'] ?? '';
            })(),
            'email' => (function () use ($creds) {
                $this->smtpHost = $creds['host'] ?? '';
                $this->smtpPort = (int) ($creds['port'] ?? 587);
                $this->smtpUsername = $creds['username'] ?? '';
                $this->smtpPassword = $creds['password'] ?? '';
                $this->smtpEncryption = $creds['encryption'] ?? 'tls';
                $this->fromAddress = $creds['from_address'] ?? '';
                $this->fromName = $creds['from_name'] ?? '';
            })(),
            'webhook' => (function () use ($creds) {
                $this->defaultUrl = $creds['default_url'] ?? '';
                $this->secret = $creds['secret'] ?? '';
            })(),
            'ntfy' => (function () use ($creds) {
                $this->ntfyBaseUrl = $creds['base_url'] ?? '';
                $this->ntfyTopic = $creds['topic'] ?? '';
                $this->ntfyPriority = $creds['default_priority'] ?? 'default';
                $this->ntfyTags = $creds['default_tags'] ?? '';
                $this->ntfyToken = $creds['token'] ?? '';
            })(),
            default => null,
        };
    }

    private function collectCredentials(): array
    {
        return match ($this->channel) {
            'telegram' => ['bot_token' => $this->botToken],
            'slack' => ['webhook_url' => $this->webhookUrl],
            'discord' => ['webhook_url' => $this->webhookUrl],
            'teams' => ['webhook_url' => $this->webhookUrl],
            'google_chat' => ['webhook_url' => $this->webhookUrl],
            'whatsapp' => ['phone_number_id' => $this->phoneNumberId, 'access_token' => $this->accessToken],
            'email' => [
                'host' => $this->smtpHost,
                'port' => $this->smtpPort,
                'username' => $this->smtpUsername,
                'password' => $this->smtpPassword,
                'encryption' => $this->smtpEncryption,
                'from_address' => $this->fromAddress,
                'from_name' => $this->fromName,
            ],
            'webhook' => ['default_url' => $this->defaultUrl, 'secret' => $this->secret],
            'ntfy' => [
                'base_url' => $this->ntfyBaseUrl,
                'topic' => $this->ntfyTopic,
                'default_priority' => $this->ntfyPriority,
                'default_tags' => $this->ntfyTags,
                'token' => $this->ntfyToken,
            ],
            default => [],
        };
    }

    private function resetFields(): void
    {
        $this->botToken = '';
        $this->webhookUrl = '';
        $this->phoneNumberId = '';
        $this->accessToken = '';
        $this->smtpHost = '';
        $this->smtpPort = 587;
        $this->smtpUsername = '';
        $this->smtpPassword = '';
        $this->smtpEncryption = 'tls';
        $this->fromAddress = '';
        $this->fromName = '';
        $this->defaultUrl = '';
        $this->secret = '';
        $this->ntfyBaseUrl = '';
        $this->ntfyTopic = '';
        $this->ntfyPriority = 'default';
        $this->ntfyTags = '';
        $this->ntfyToken = '';
    }

    private function updateTestStatus(string $status): void
    {
        $teamId = $this->resolveTeamId();

        OutboundConnectorConfig::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('channel', $this->channel)
            ->update([
                'last_tested_at' => now(),
                'last_test_status' => $status,
            ]);
    }

    private function resolveTeamId(): string
    {
        if (app()->bound('team')) {
            return app('team')->id;
        }

        return auth()->user()->currentTeam?->id ?? auth()->user()->teams->first()->id;
    }
}
