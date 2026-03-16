<?php

namespace App\Livewire\OutboundConnectors;

use App\Domain\Email\Models\EmailTemplate;
use App\Domain\Outbound\Models\OutboundConnectorConfig;
use Livewire\Component;

class OutboundConnectorsPage extends Component
{
    // SMTP server
    public string $host = '';
    public string $port = '587';
    public string $encryption = 'tls';
    public string $username = '';
    public string $password = '';

    // Sender identity
    public string $fromAddress = '';
    public string $fromName = '';

    // Delivery defaults
    public string $defaultRecipient = '';
    public string $defaultTemplateId = '';

    // Connector state
    public bool $isActive = true;
    public ?string $lastTestedAt = null;
    public ?string $lastTestStatus = null;

    // UI feedback
    public ?string $testMessage = null;
    public ?string $testError = null;

    public function mount(): void
    {
        $team = auth()->user()->currentTeam;
        $config = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'email')
            ->first();

        if ($config) {
            $creds = $config->credentials ?? [];
            $this->host = $creds['host'] ?? '';
            $this->port = (string) ($creds['port'] ?? '587');
            $this->encryption = $creds['encryption'] ?? 'tls';
            $this->username = $creds['username'] ?? '';
            $this->fromAddress = $creds['from_address'] ?? '';
            $this->fromName = $creds['from_name'] ?? '';
            $this->defaultRecipient = $creds['default_recipient'] ?? '';
            $this->defaultTemplateId = $creds['default_template_id'] ?? '';
            $this->isActive = (bool) $config->is_active;
            $this->lastTestedAt = $config->last_tested_at?->diffForHumans();
            $this->lastTestStatus = $config->last_test_status;
        }
    }

    public function save(): void
    {
        $this->validate([
            'host' => 'required|string|max:255',
            'port' => 'required|integer|between:1,65535',
            'encryption' => 'required|in:tls,ssl,none',
            'fromAddress' => 'required|email|max:255',
            'fromName' => 'nullable|string|max:255',
            'defaultRecipient' => 'nullable|email|max:255',
            'defaultTemplateId' => 'nullable|uuid',
        ]);

        $team = auth()->user()->currentTeam;

        $existing = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'email')
            ->first();

        $credentials = [
            'host' => $this->host,
            'port' => (int) $this->port,
            'encryption' => $this->encryption,
            'username' => $this->username,
            'from_address' => $this->fromAddress,
            'from_name' => $this->fromName,
            'default_recipient' => $this->defaultRecipient,
            'default_template_id' => $this->defaultTemplateId ?: null,
        ];

        // Preserve existing password if field is left blank
        if ($this->password) {
            $credentials['password'] = $this->password;
        } elseif ($existing) {
            $credentials['password'] = $existing->credentials['password'] ?? '';
        } else {
            $credentials['password'] = '';
        }

        OutboundConnectorConfig::updateOrCreate(
            ['team_id' => $team->id, 'channel' => 'email'],
            ['credentials' => $credentials, 'is_active' => $this->isActive],
        );

        $this->password = '';
        $this->testMessage = null;
        $this->testError = null;

        session()->flash('message', 'Email connector saved successfully.');
    }

    public function testConnection(): void
    {
        $team = auth()->user()->currentTeam;
        $config = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'email')
            ->first();

        if (! $config) {
            $this->testError = 'Save the connector first before testing.';

            return;
        }

        $creds = $config->credentials ?? [];
        $host = $creds['host'] ?? '';
        $port = (int) ($creds['port'] ?? 587);

        if (! $host) {
            $this->testError = 'SMTP host is not configured.';

            return;
        }

        $connection = @fsockopen($host, $port, $errno, $errstr, 5);

        if ($connection) {
            $banner = fgets($connection, 512);
            fclose($connection);
            $status = str_starts_with(trim($banner), '220') ? 'success' : 'failed';
        } else {
            $status = 'failed';
            $banner = $errstr;
        }

        $config->update([
            'last_tested_at' => now(),
            'last_test_status' => $status,
        ]);

        $this->lastTestedAt = 'just now';
        $this->lastTestStatus = $status;

        if ($status === 'success') {
            $this->testMessage = "Connected to {$host}:{$port} successfully.";
            $this->testError = null;
        } else {
            $this->testError = "Could not connect to {$host}:{$port}: {$banner}";
            $this->testMessage = null;
        }
    }

    public function render()
    {
        $templates = EmailTemplate::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('livewire.outbound-connectors.outbound-connectors-page', [
            'templates' => $templates,
        ])->layout('layouts.app', ['header' => 'Email Delivery']);
    }
}
