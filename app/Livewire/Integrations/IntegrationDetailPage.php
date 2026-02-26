<?php

namespace App\Livewire\Integrations;

use App\Domain\Integration\Actions\DisconnectIntegrationAction;
use App\Domain\Integration\Actions\PingIntegrationAction;
use App\Domain\Integration\Models\Integration;
use App\Domain\Integration\Services\IntegrationManager;
use Livewire\Component;

class IntegrationDetailPage extends Component
{
    public Integration $integration;

    public function mount(Integration $integration): void
    {
        $this->integration = $integration;
    }

    public function ping(PingIntegrationAction $action): void
    {
        $result = $action->execute($this->integration);
        $this->integration->refresh();

        if ($result->healthy) {
            session()->flash('message', 'Ping successful ('.$result->latencyMs.'ms).');
        } else {
            session()->flash('error', 'Ping failed: '.$result->message);
        }
    }

    public function disconnect(DisconnectIntegrationAction $action): void
    {
        $action->execute($this->integration);
        session()->flash('message', 'Integration disconnected.');
        $this->redirect(route('integrations.index'), navigate: true);
    }

    public function render()
    {
        $manager = app(IntegrationManager::class);
        $driver = $manager->driver($this->integration->getAttribute('driver'));

        return view('livewire.integrations.integration-detail-page', [
            'driver' => $driver,
            'triggers' => $driver->triggers(),
            'actions' => $driver->actions(),
            'webhookRoutes' => $this->integration->webhookRoutes,
        ])->layout('layouts.app', ['header' => 'Integration: '.$this->integration->name]);
    }
}
