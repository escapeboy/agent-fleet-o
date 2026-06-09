<?php

namespace App\Livewire\OutboundConnectors;

use App\Domain\Outbound\Models\OutboundConnectorConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

/**
 * Livewire component for configuring the Telegram Bot API outbound connector.
 *
 * Credentials are stored encrypted in OutboundConnectorConfig (channel=telegram)
 * and resolved at send time by OutboundCredentialResolver. The bot_token field is
 * write-only: it is preserved on save when left blank.
 */
class TelegramOutboundPage extends Component
{
    /** Default chat ID used when a proposal target omits one. */
    public string $chatId = '';

    /** Telegram bot token (write-only). */
    public string $botToken = '';

    public bool $isActive = true;

    public ?string $lastTestedAt = null;

    public ?string $lastTestStatus = null;

    public ?string $testMessage = null;

    public ?string $testError = null;

    public function mount(): void
    {
        $team = auth()->user()->currentTeam;
        $config = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'telegram')
            ->first();

        if ($config) {
            $creds = $config->credentials ?? [];
            $this->chatId = $creds['chat_id'] ?? '';
            // bot_token is never pre-filled (write-only)
            $this->isActive = (bool) $config->is_active;
            $this->lastTestedAt = $config->last_tested_at?->diffForHumans();
            $this->lastTestStatus = $config->last_test_status;
        }
    }

    public function save(): void
    {
        Gate::authorize('manage-team');

        $this->validate([
            'chatId' => 'nullable|string|max:64',
        ]);

        $team = auth()->user()->currentTeam;

        $existing = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'telegram')
            ->first();

        $credentials = [
            'chat_id' => $this->chatId ?: null,
        ];

        if ($this->botToken) {
            $credentials['bot_token'] = $this->botToken;
        } elseif ($existing) {
            $credentials['bot_token'] = $existing->credentials['bot_token'] ?? '';
        } else {
            $credentials['bot_token'] = '';
        }

        OutboundConnectorConfig::updateOrCreate(
            ['team_id' => $team->id, 'channel' => 'telegram'],
            ['credentials' => $credentials, 'is_active' => $this->isActive],
        );

        $this->botToken = '';
        $this->testMessage = null;
        $this->testError = null;

        session()->flash('message', 'Telegram connector saved successfully.');
    }

    public function testConnection(): void
    {
        Gate::authorize('manage-team');

        $team = auth()->user()->currentTeam;
        $config = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'telegram')
            ->first();

        if (! $config) {
            $this->testError = 'Save the connector first before testing.';

            return;
        }

        $token = $config->credentials['bot_token'] ?? '';
        if (! $token) {
            $this->testError = 'Bot token is not configured.';

            return;
        }

        $response = null;

        try {
            $response = Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getMe");
            $status = ($response->successful() && $response->json('ok')) ? 'success' : 'failed';
        } catch (\Throwable) {
            $status = 'failed';
        }

        $config->update([
            'last_tested_at' => now(),
            'last_test_status' => $status,
        ]);

        $this->lastTestedAt = 'just now';
        $this->lastTestStatus = $status;

        if ($status === 'success' && $response !== null) {
            $this->testMessage = 'Connected as @'.$response->json('result.username');
            $this->testError = null;
        } else {
            $this->testError = 'Telegram API error: '.($response?->json('description') ?? 'Invalid bot token');
            $this->testMessage = null;
        }
    }

    public function render(): View
    {
        return view('livewire.outbound-connectors.telegram-outbound-page')
            ->layout('layouts.app', ['header' => 'Telegram Delivery']);
    }
}
