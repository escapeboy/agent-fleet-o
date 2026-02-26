<?php

namespace App\Livewire\Integrations;

use App\Domain\Integration\Actions\ConnectIntegrationAction;
use App\Domain\Integration\Actions\DisconnectIntegrationAction;
use App\Domain\Integration\Actions\PingIntegrationAction;
use App\Domain\Integration\Enums\IntegrationStatus;
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

    public function mount(): void
    {
        //
    }

    public function openConnectForm(string $driver): void
    {
        $this->connectDriver = $driver;
        $this->connectName = ucfirst($driver).' Integration';
        $this->connectCredentials = [];
        $this->connectConfig = [];
        $this->credentialKey = '';
        $this->credentialValue = '';
        $this->showConnectForm = true;
    }

    public function closeConnectForm(): void
    {
        $this->showConnectForm = false;
        $this->connectDriver = '';
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
