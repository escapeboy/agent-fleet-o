<?php

namespace App\Livewire\OutboundConnectors;

use App\Domain\Outbound\Models\OutboundConnectorConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

/**
 * Livewire component for configuring the Signal outbound connector.
 *
 * Sends via a self-hosted bbernhard/signal-cli-rest-api sidecar. Credentials are
 * stored encrypted in OutboundConnectorConfig (channel=signal_protocol) and resolved
 * at send time by OutboundCredentialResolver.
 */
class SignalProtocolOutboundPage extends Component
{
    public string $apiUrl = '';

    public string $phoneNumber = '';

    public string $recipient = '';

    public bool $isActive = true;

    public ?string $lastTestedAt = null;

    public ?string $lastTestStatus = null;

    public ?string $testMessage = null;

    public ?string $testError = null;

    public function mount(): void
    {
        $team = auth()->user()->currentTeam;
        $config = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'signal_protocol')
            ->first();

        if ($config) {
            $creds = $config->credentials ?? [];
            $this->apiUrl = $creds['api_url'] ?? '';
            $this->phoneNumber = $creds['phone_number'] ?? '';
            $this->recipient = $creds['recipient'] ?? '';
            $this->isActive = (bool) $config->is_active;
            $this->lastTestedAt = $config->last_tested_at?->diffForHumans();
            $this->lastTestStatus = $config->last_test_status;
        }
    }

    public function save(): void
    {
        Gate::authorize('manage-team');

        $this->validate([
            'apiUrl' => 'nullable|url|max:512',
            'phoneNumber' => 'nullable|string|max:32',
            'recipient' => 'nullable|string|max:32',
        ]);

        $team = auth()->user()->currentTeam;

        $credentials = [
            'api_url' => $this->apiUrl ?: null,
            'phone_number' => $this->phoneNumber ?: null,
            'recipient' => $this->recipient ?: null,
        ];

        OutboundConnectorConfig::updateOrCreate(
            ['team_id' => $team->id, 'channel' => 'signal_protocol'],
            ['credentials' => $credentials, 'is_active' => $this->isActive],
        );

        $this->testMessage = null;
        $this->testError = null;

        session()->flash('message', 'Signal connector saved successfully.');
    }

    public function testConnection(): void
    {
        Gate::authorize('manage-team');

        $team = auth()->user()->currentTeam;
        $config = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'signal_protocol')
            ->first();

        if (! $config) {
            $this->testError = 'Save the connector first before testing.';

            return;
        }

        $apiUrl = rtrim($config->credentials['api_url'] ?? 'http://signal-sidecar:8080', '/');

        $response = null;

        try {
            $response = Http::timeout(10)->get("{$apiUrl}/v1/about");
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
            $this->testMessage = 'signal-cli-rest-api reachable at '.$apiUrl;
            $this->testError = null;
        } else {
            $this->testError = 'signal-cli-rest-api error: '.($response?->status() ?? 'unreachable');
            $this->testMessage = null;
        }
    }

    public function render(): View
    {
        return view('livewire.outbound-connectors.signal-protocol-outbound-page')
            ->layout('layouts.app', ['header' => 'Signal Delivery']);
    }
}
