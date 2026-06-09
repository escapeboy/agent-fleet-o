<?php

namespace App\Livewire\OutboundConnectors;

use App\Domain\Outbound\Models\OutboundConnectorConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

/**
 * Livewire component for configuring the Google Chat outbound connector.
 *
 * Credentials are stored encrypted in OutboundConnectorConfig (channel=google_chat)
 * and resolved at send time by OutboundCredentialResolver. The webhook_url field
 * is write-only: it is preserved on save when left blank.
 */
class GoogleChatOutboundPage extends Component
{
    /** Google Chat space webhook URL (write-only). */
    public string $webhookUrl = '';

    public bool $isActive = true;

    public ?string $lastTestedAt = null;

    public ?string $lastTestStatus = null;

    public ?string $testMessage = null;

    public ?string $testError = null;

    public function mount(): void
    {
        $team = auth()->user()->currentTeam;
        $config = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'google_chat')
            ->first();

        if ($config) {
            // webhook_url is never pre-filled (write-only)
            $this->isActive = (bool) $config->is_active;
            $this->lastTestedAt = $config->last_tested_at?->diffForHumans();
            $this->lastTestStatus = $config->last_test_status;
        }
    }

    public function save(): void
    {
        Gate::authorize('manage-team');

        $this->validate([
            'webhookUrl' => 'nullable|url|max:1024',
        ]);

        $team = auth()->user()->currentTeam;

        $existing = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'google_chat')
            ->first();

        $credentials = [];

        if ($this->webhookUrl) {
            $credentials['webhook_url'] = $this->webhookUrl;
        } elseif ($existing) {
            $credentials['webhook_url'] = $existing->credentials['webhook_url'] ?? '';
        } else {
            $credentials['webhook_url'] = '';
        }

        OutboundConnectorConfig::updateOrCreate(
            ['team_id' => $team->id, 'channel' => 'google_chat'],
            ['credentials' => $credentials, 'is_active' => $this->isActive],
        );

        $this->webhookUrl = '';
        $this->testMessage = null;
        $this->testError = null;

        session()->flash('message', 'Google Chat connector saved successfully.');
    }

    public function testConnection(): void
    {
        Gate::authorize('manage-team');

        $team = auth()->user()->currentTeam;
        $config = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'google_chat')
            ->first();

        if (! $config) {
            $this->testError = 'Save the connector first before testing.';

            return;
        }

        $url = $config->credentials['webhook_url'] ?? '';
        if (! $url) {
            $this->testError = 'Webhook URL is not configured.';

            return;
        }

        $response = null;

        try {
            $response = Http::timeout(10)->post($url, ['text' => '[Test] FleetQ connectivity check']);
            $status = $response->successful() ? 'success' : 'failed';
        } catch (\Throwable) {
            $status = 'failed';
        }

        $config->update([
            'last_tested_at' => now(),
            'last_test_status' => $status,
        ]);

        $this->lastTestedAt = 'just now';
        $this->lastTestStatus = $status;

        if ($status === 'success') {
            $this->testMessage = 'Test message sent successfully.';
            $this->testError = null;
        } else {
            $this->testError = 'Google Chat returned '.($response?->status() ?? 'an error').'.';
            $this->testMessage = null;
        }
    }

    public function render(): View
    {
        return view('livewire.outbound-connectors.google-chat-outbound-page')
            ->layout('layouts.app', ['header' => 'Google Chat Delivery']);
    }
}
