<?php

namespace App\Livewire\Integrations;

use App\Domain\Integration\Actions\ConnectIntegrationAction;
use App\Domain\Integration\Actions\DisconnectIntegrationAction;
use App\Domain\Integration\Actions\OAuthConnectAction;
use App\Domain\Integration\Actions\PingIntegrationAction;
use App\Domain\Integration\Models\Integration;
use App\Domain\Integration\Services\IntegrationManager;
use Illuminate\Support\Facades\Gate;
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

    public string $search = '';

    public string $categoryFilter = '';

    public function mount(): void
    {
        $autoConnect = request()->query('connect');
        if ($autoConnect && array_key_exists($autoConnect, config('integrations.drivers', []))) {
            $this->openConnectForm($autoConnect);
        }
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
        } catch (\Throwable) {
            // Fallback: use credential_fields from config if no driver class exists
            $configFields = config("integrations.drivers.{$driver}.credential_fields", []);
            $this->credentialSchema = array_map(fn ($f) => array_merge(
                ['type' => 'string', 'required' => true],
                $f,
            ), $configFields);
        }

        // Pre-populate keys with defaults so wire:model bindings exist
        $this->connectCredentials = array_map(
            fn ($field) => $field['default'] ?? '',
            $this->credentialSchema,
        );

        $this->showConnectForm = true;
    }

    public function closeConnectForm(): void
    {
        $this->showConnectForm = false;
        $this->connectDriver = '';
    }

    public function connectOAuth(OAuthConnectAction $action): mixed
    {
        Gate::authorize('edit-content');

        $this->validate([
            'connectDriver' => 'required|string',
            'connectName' => 'required|string|min:2|max:255',
            // Fix 2: validate subdomain format for subdomain-based OAuth providers (Zendesk, Freshdesk)
            'connectConfig.subdomain' => 'sometimes|nullable|regex:/^[a-zA-Z0-9][a-zA-Z0-9-]{0,61}[a-zA-Z0-9]$/|max:63',
        ]);

        $team = auth()->user()->currentTeam;

        try {
            $url = $action->execute(
                teamId: $team->getKey(),
                driver: $this->connectDriver,
                name: $this->connectName,
                subdomain: $this->connectConfig['subdomain'] ?? null,
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
        Gate::authorize('edit-content');

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

            $driverLabel = config("integrations.drivers.{$this->connectDriver}.label", ucfirst($this->connectDriver));
            $this->closeConnectForm();
            session()->flash('message', "{$driverLabel} integration connected.");
        } catch (\Throwable $e) {
            session()->flash('error', 'Connection failed: '.$e->getMessage());
        }
    }

    public function disconnect(string $integrationId, DisconnectIntegrationAction $action): void
    {
        Gate::authorize('edit-content');

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

        $allDrivers = config('integrations.drivers', []);

        $availableDrivers = array_filter($allDrivers, function ($info) {
            if ($this->categoryFilter && ($info['category'] ?? '') !== $this->categoryFilter) {
                return false;
            }
            if ($this->search) {
                $needle = strtolower($this->search);

                return str_contains(strtolower($info['label']), $needle)
                    || str_contains(strtolower($info['description'] ?? ''), $needle);
            }

            return true;
        });

        // Extract unique categories for filter tabs
        $categories = collect($allDrivers)
            ->pluck('category')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return view('livewire.integrations.integration-list-page', [
            'connectedIntegrations' => $connectedIntegrations,
            'availableDrivers' => $availableDrivers,
            'categories' => $categories,
        ])->layout('layouts.app', ['header' => 'Integrations']);
    }
}
