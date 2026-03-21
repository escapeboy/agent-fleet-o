<?php

namespace App\Livewire\OutboundConnectors;

use App\Domain\Outbound\Models\OutboundConnectorConfig;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class WebhookOutboundPage extends Component
{
    public string $webhookUrl = '';

    public string $secret = '';

    public string $method = 'POST';

    public string $contentType = 'application/json';

    public bool $isActive = true;

    public ?string $lastTestedAt = null;

    public ?string $lastTestStatus = null;

    public ?string $testMessage = null;

    public ?string $testError = null;

    public function mount(): void
    {
        $team = auth()->user()->currentTeam;
        $config = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'webhook')
            ->first();

        if ($config) {
            $creds = $config->credentials ?? [];
            $this->webhookUrl = $creds['url'] ?? '';
            $this->method = $creds['method'] ?? 'POST';
            $this->contentType = $creds['content_type'] ?? 'application/json';
            $this->isActive = (bool) $config->is_active;
            $this->lastTestedAt = $config->last_tested_at?->diffForHumans();
            $this->lastTestStatus = $config->last_test_status;
        }
    }

    public function save(): void
    {
        $this->validate([
            'webhookUrl' => 'required|url|max:2048',
            'method' => 'required|in:POST,PUT,PATCH',
            'contentType' => 'required|in:application/json,application/x-www-form-urlencoded',
        ]);

        $team = auth()->user()->currentTeam;

        $existing = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'webhook')
            ->first();

        $credentials = [
            'url' => $this->webhookUrl,
            'method' => $this->method,
            'content_type' => $this->contentType,
        ];

        if ($this->secret) {
            $credentials['secret'] = $this->secret;
        } elseif ($existing) {
            $credentials['secret'] = $existing->credentials['secret'] ?? '';
        }

        OutboundConnectorConfig::updateOrCreate(
            ['team_id' => $team->id, 'channel' => 'webhook'],
            ['credentials' => $credentials, 'is_active' => $this->isActive],
        );

        $this->secret = '';
        $this->testMessage = null;
        $this->testError = null;

        session()->flash('message', 'Webhook connector saved successfully.');
    }

    public function testConnection(): void
    {
        $team = auth()->user()->currentTeam;
        $config = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'webhook')
            ->first();

        if (! $config) {
            $this->testError = 'Save the connector first before testing.';

            return;
        }

        $url = $config->credentials['url'] ?? '';

        if (! $url) {
            $this->testError = 'Webhook URL is not configured.';

            return;
        }

        try {
            $response = Http::timeout(10)
                ->post($url, ['test' => true, 'source' => 'fleetq', 'timestamp' => now()->toIso8601String()]);

            $status = $response->successful() ? 'success' : 'failed';
        } catch (\Throwable $e) {
            $status = 'failed';
        }

        $config->update([
            'last_tested_at' => now(),
            'last_test_status' => $status,
        ]);

        $this->lastTestedAt = 'just now';
        $this->lastTestStatus = $status;

        if ($status === 'success') {
            $this->testMessage = 'Webhook endpoint responded successfully.';
            $this->testError = null;
        } else {
            $this->testError = "Could not reach webhook endpoint at {$url}.";
            $this->testMessage = null;
        }
    }

    public function render()
    {
        return view('livewire.outbound-connectors.webhook-outbound-page')
            ->layout('layouts.app', ['header' => 'Webhook Delivery']);
    }
}
