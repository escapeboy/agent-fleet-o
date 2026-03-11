<?php

namespace App\Livewire\Integrations;

use App\Domain\Integration\Actions\ConnectIntegrationAction;
use App\Domain\Integration\Actions\DisconnectIntegrationAction;
use App\Domain\Integration\Actions\OAuthConnectAction;
use App\Domain\Integration\Actions\PingIntegrationAction;
use App\Domain\Integration\Models\Integration;
use App\Domain\Integration\Services\IntegrationManager;
use Livewire\Component;

class IntegrationListPage extends Component
{
    public string $connectDriver = '';

    public string $connectName = '';

    public array $connectCredentials = [];

    public array $connectConfig = [];

    public bool $showConnectForm = false;

    public string $credentialKey = '';

    public string $credentialValue = '';

    /** @var array<string, array{type: string, required: bool, label: string, hint?: string}> */
    public array $credentialSchema = [];

    public function mount(): void
    {
        //
    }

    public function openConnectForm(string $driver): void
    {
        $this->connectDriver = $driver;
        $this->connectName = ucfirst($driver).' Integration';
        $this->connectConfig = [];
        $this->credentialKey = '';
        $this->credentialValue = '';

        try {
            $schema = app(IntegrationManager::class)->driver($driver)->credentialSchema();
            $this->credentialSchema = $schema;
            // Pre-populate keys so wire:model bindings exist
            $this->connectCredentials = array_fill_keys(array_keys($schema), '');
        } catch (\Throwable) {
            $this->credentialSchema = [];
            $this->connectCredentials = [];
        }

        $this->showConnectForm = true;
    }

    public function closeConnectForm(): void
    {
        $this->showConnectForm = false;
        $this->connectDriver = '';
    }

    public function connectOAuth(OAuthConnectAction $action): mixed
    {
        $this->validate([
            'connectDriver' => 'required|string',
            'connectName' => 'required|string|min:2|max:255',
        ]);

        $team = auth()->user()->currentTeam;

        try {
            $url = $action->execute(
                teamId: $team->getKey(),
                driver: $this->connectDriver,
                name: $this->connectName,
            );

            return redirect()->away($url);
        } catch (\Throwable $e) {
            session()->flash('error', 'OAuth2 initiation failed: '.$e->getMessage());

            return null;
        }
    }

    public function addCredential(): void
    {
        $key = trim($this->credentialKey);
        $value = trim($this->credentialValue);

        if ($key === '' || $value === '') {
            return;
        }

        $this->connectCredentials[$key] = $value;
        $this->credentialKey = '';
        $this->credentialValue = '';
    }

    public function removeCredential(string $key): void
    {
        unset($this->connectCredentials[$key]);
    }

    public function connect(ConnectIntegrationAction $action): void
    {
        $this->validate([
            'connectDriver' => 'required|string',
            'connectName' => 'required|string|min:2|max:255',
        ]);

        $team = auth()->user()->currentTeam;

        try {
            $action->execute(
                teamId: $team->getKey(),
                driver: $this->connectDriver,
                name: $this->connectName,
                credentials: $this->connectCredentials,
                config: $this->connectConfig,
            );

            $this->closeConnectForm();
            session()->flash('message', ucfirst($this->connectDriver).' integration connected.');
        } catch (\Throwable $e) {
            session()->flash('error', 'Connection failed: '.$e->getMessage());
        }
    }

    public function disconnect(string $integrationId, DisconnectIntegrationAction $action): void
    {
        $integration = Integration::findOrFail($integrationId);
        $action->execute($integration);

        session()->flash('message', 'Integration disconnected.');
    }

    public function ping(string $integrationId, PingIntegrationAction $action): void
    {
        $integration = Integration::findOrFail($integrationId);
        $result = $action->execute($integration);

        if ($result->healthy) {
            session()->flash('message', 'Ping successful ('.$result->latencyMs.'ms).');
        } else {
            session()->flash('error', 'Ping failed: '.$result->message);
        }
    }

    public function render()
    {
        $team = auth()->user()->currentTeam;
        $manager = app(IntegrationManager::class);

        $connectedIntegrations = $team
            ? Integration::where('team_id', $team->getKey())->withTrashed(false)->get()
            : collect();

        $availableDrivers = config('integrations.drivers', []);

        return view('livewire.integrations.integration-list-page', [
            'connectedIntegrations' => $connectedIntegrations,
            'availableDrivers' => $availableDrivers,
        ])->layout('layouts.app', ['header' => 'Integrations']);
    }
}
