<?php

namespace App\Livewire\OutboundConnectors;

use App\Domain\Outbound\Models\OutboundConnectorConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

/**
 * Livewire component for configuring the Supabase Realtime Broadcast outbound connector.
 *
 * Credentials are stored encrypted in OutboundConnectorConfig (channel=supabase_realtime)
 * and resolved at send time by OutboundCredentialResolver. The key field is write-only:
 * it is preserved on save when left blank.
 */
class SupabaseRealtimeOutboundPage extends Component
{
    public string $ref = '';

    public string $channel = 'agent:results';

    public string $event = 'message';

    /** Supabase apikey (anon or service role) — write-only. */
    public string $apiKey = '';

    public bool $isActive = true;

    public ?string $lastTestedAt = null;

    public ?string $lastTestStatus = null;

    public ?string $testMessage = null;

    public ?string $testError = null;

    public function mount(): void
    {
        $team = auth()->user()->currentTeam;
        $config = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'supabase_realtime')
            ->first();

        if ($config) {
            $creds = $config->credentials ?? [];
            $this->ref = $creds['ref'] ?? '';
            $this->channel = $creds['channel'] ?? 'agent:results';
            $this->event = $creds['event'] ?? 'message';
            // key is never pre-filled (write-only)
            $this->isActive = (bool) $config->is_active;
            $this->lastTestedAt = $config->last_tested_at?->diffForHumans();
            $this->lastTestStatus = $config->last_test_status;
        }
    }

    public function save(): void
    {
        Gate::authorize('manage-team');

        $this->validate([
            'ref' => 'nullable|string|max:128',
            'channel' => 'nullable|string|max:255',
            'event' => 'nullable|string|max:255',
        ]);

        $team = auth()->user()->currentTeam;

        $existing = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'supabase_realtime')
            ->first();

        $credentials = [
            'ref' => $this->ref ?: null,
            'channel' => $this->channel ?: null,
            'event' => $this->event ?: null,
        ];

        if ($this->apiKey) {
            $credentials['key'] = $this->apiKey;
        } elseif ($existing) {
            $credentials['key'] = $existing->credentials['key'] ?? '';
        } else {
            $credentials['key'] = '';
        }

        OutboundConnectorConfig::updateOrCreate(
            ['team_id' => $team->id, 'channel' => 'supabase_realtime'],
            ['credentials' => $credentials, 'is_active' => $this->isActive],
        );

        $this->apiKey = '';
        $this->testMessage = null;
        $this->testError = null;

        session()->flash('message', 'Supabase Realtime connector saved successfully.');
    }

    public function testConnection(): void
    {
        Gate::authorize('manage-team');

        $team = auth()->user()->currentTeam;
        $config = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'supabase_realtime')
            ->first();

        if (! $config) {
            $this->testError = 'Save the connector first before testing.';

            return;
        }

        $creds = $config->credentials ?? [];
        $ref = $creds['ref'] ?? '';
        $key = $creds['key'] ?? '';

        if (! $ref || ! $key) {
            $this->testError = 'Project ref and key are not configured.';

            return;
        }

        $response = null;

        try {
            $response = Http::withHeaders([
                'apikey' => $key,
                'Authorization' => "Bearer {$key}",
                'Content-Type' => 'application/json',
            ])->timeout(10)->post("https://{$ref}.supabase.co/realtime/v1/api/broadcast", [
                'messages' => [[
                    'topic' => $creds['channel'] ?? 'agent:results',
                    'event' => $creds['event'] ?? 'message',
                    'payload' => ['test' => true, 'source' => 'fleetq'],
                ]],
            ]);
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
            $this->testMessage = 'Test broadcast sent successfully.';
            $this->testError = null;
        } else {
            $this->testError = 'Supabase returned '.($response?->status() ?? 'an error').'.';
            $this->testMessage = null;
        }
    }

    public function render(): View
    {
        return view('livewire.outbound-connectors.supabase-realtime-outbound-page')
            ->layout('layouts.app', ['header' => 'Supabase Realtime Delivery']);
    }
}
