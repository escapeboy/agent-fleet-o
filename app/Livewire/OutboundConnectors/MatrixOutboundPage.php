<?php

namespace App\Livewire\OutboundConnectors;

use App\Domain\Outbound\Models\OutboundConnectorConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

/**
 * Livewire component for configuring the Matrix outbound connector.
 *
 * Credentials are stored encrypted in OutboundConnectorConfig (channel=matrix)
 * and resolved at send time by OutboundCredentialResolver. The access_token field
 * is write-only: it is preserved on save when left blank.
 */
class MatrixOutboundPage extends Component
{
    public string $homeserverUrl = '';

    public string $roomId = '';

    /** Matrix bot access token (write-only). */
    public string $accessToken = '';

    public bool $isActive = true;

    public ?string $lastTestedAt = null;

    public ?string $lastTestStatus = null;

    public ?string $testMessage = null;

    public ?string $testError = null;

    public function mount(): void
    {
        $team = auth()->user()->currentTeam;
        $config = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'matrix')
            ->first();

        if ($config) {
            $creds = $config->credentials ?? [];
            $this->homeserverUrl = $creds['homeserver_url'] ?? '';
            $this->roomId = $creds['room_id'] ?? '';
            // access_token is never pre-filled (write-only)
            $this->isActive = (bool) $config->is_active;
            $this->lastTestedAt = $config->last_tested_at?->diffForHumans();
            $this->lastTestStatus = $config->last_test_status;
        }
    }

    public function save(): void
    {
        Gate::authorize('manage-team');

        $this->validate([
            'homeserverUrl' => 'nullable|url|max:512',
            'roomId' => 'nullable|string|max:255',
        ]);

        $team = auth()->user()->currentTeam;

        $existing = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'matrix')
            ->first();

        $credentials = [
            'homeserver_url' => $this->homeserverUrl ?: null,
            'room_id' => $this->roomId ?: null,
        ];

        if ($this->accessToken) {
            $credentials['access_token'] = $this->accessToken;
        } elseif ($existing) {
            $credentials['access_token'] = $existing->credentials['access_token'] ?? '';
        } else {
            $credentials['access_token'] = '';
        }

        OutboundConnectorConfig::updateOrCreate(
            ['team_id' => $team->id, 'channel' => 'matrix'],
            ['credentials' => $credentials, 'is_active' => $this->isActive],
        );

        $this->accessToken = '';
        $this->testMessage = null;
        $this->testError = null;

        session()->flash('message', 'Matrix connector saved successfully.');
    }

    public function testConnection(): void
    {
        Gate::authorize('manage-team');

        $team = auth()->user()->currentTeam;
        $config = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'matrix')
            ->first();

        if (! $config) {
            $this->testError = 'Save the connector first before testing.';

            return;
        }

        $creds = $config->credentials ?? [];
        $homeserverUrl = rtrim($creds['homeserver_url'] ?? '', '/');
        $token = $creds['access_token'] ?? '';

        if (! $homeserverUrl || ! $token) {
            $this->testError = 'Homeserver URL and access token are not configured.';

            return;
        }

        $response = null;

        try {
            $response = Http::timeout(10)
                ->withToken($token)
                ->get("{$homeserverUrl}/_matrix/client/v3/account/whoami");
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

        if ($status === 'success' && $response !== null) {
            $this->testMessage = 'Connected as '.($response->json('user_id') ?? 'verified');
            $this->testError = null;
        } else {
            $this->testError = 'Matrix API error: '.($response?->json('error') ?? 'Invalid homeserver URL or access token');
            $this->testMessage = null;
        }
    }

    public function render(): View
    {
        return view('livewire.outbound-connectors.matrix-outbound-page')
            ->layout('layouts.app', ['header' => 'Matrix Delivery']);
    }
}
