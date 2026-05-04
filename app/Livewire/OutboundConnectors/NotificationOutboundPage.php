<?php

namespace App\Livewire\OutboundConnectors;

use App\Domain\Outbound\Models\OutboundConnectorConfig;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class NotificationOutboundPage extends Component
{
    public bool $isActive = true;

    public bool $notifyAllMembers = true;

    public string $defaultPriority = 'normal';

    public function mount(): void
    {
        $team = auth()->user()->currentTeam;
        $config = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'notification')
            ->first();

        if ($config) {
            $creds = $config->credentials ?? [];
            $this->notifyAllMembers = $creds['notify_all_members'] ?? true;
            $this->defaultPriority = $creds['default_priority'] ?? 'normal';
            $this->isActive = (bool) $config->is_active;
        }
    }

    public function save(): void
    {
        Gate::authorize('manage-team');

        $this->validate([
            'defaultPriority' => 'required|in:low,normal,high',
        ]);

        $team = auth()->user()->currentTeam;

        OutboundConnectorConfig::updateOrCreate(
            ['team_id' => $team->id, 'channel' => 'notification'],
            [
                'credentials' => [
                    'notify_all_members' => $this->notifyAllMembers,
                    'default_priority' => $this->defaultPriority,
                ],
                'is_active' => $this->isActive,
            ],
        );

        session()->flash('message', 'Notification connector saved successfully.');
    }

    public function render()
    {
        return view('livewire.outbound-connectors.notification-outbound-page')
            ->layout('layouts.app', ['header' => 'In-App Notifications']);
    }
}
