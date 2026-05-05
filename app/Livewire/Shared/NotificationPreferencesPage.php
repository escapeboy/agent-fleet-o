<?php

namespace App\Livewire\Shared;

use App\Domain\Shared\Services\NotificationPreferencesService;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class NotificationPreferencesPage extends Component
{
    /** @var array<string, array<string, bool>> preferences[type][channel] = bool */
    public array $preferences = [];

    public string $pushStatus = 'unknown';

    public function mount(): void
    {
        $user = auth()->user();
        $saved = $user->getPreferences();
        $available = NotificationPreferencesService::availableChannels();

        foreach ($available as $type => $channels) {
            foreach ($channels as $channel) {
                $this->preferences[$type][$channel] = in_array($channel, $saved[$type] ?? []);
            }
        }
    }

    public function setPushStatus(string $status): void
    {
        $valid = ['unsupported', 'denied', 'unsubscribed', 'subscribed'];
        $this->pushStatus = in_array($status, $valid) ? $status : 'unknown';
    }

    public function savePushSubscription(array $payload): void
    {
        Gate::authorize('update-self');

        $user = auth()->user();

        if (! $user || empty($payload['endpoint'])) {
            return;
        }

        $user->updatePushSubscription(
            endpoint: $payload['endpoint'],
            key: $payload['keys']['p256dh'] ?? null,
            token: $payload['keys']['auth'] ?? null,
            contentEncoding: 'aesgcm',
        );

        $this->pushStatus = 'subscribed';
        session()->flash('message', 'Push notifications enabled.');
    }

    public function deletePushSubscription(string $endpoint): void
    {
        Gate::authorize('update-self');

        auth()->user()?->deletePushSubscription($endpoint);
        $this->pushStatus = 'unsubscribed';
        session()->flash('message', 'Push notifications disabled.');
    }

    public function save(): void
    {
        Gate::authorize('update-self');

        $user = auth()->user();
        $available = NotificationPreferencesService::availableChannels();

        $toSave = [];
        foreach ($available as $type => $channels) {
            $toSave[$type] = array_values(array_filter(
                $channels,
                fn ($ch) => (bool) ($this->preferences[$type][$ch] ?? false),
            ));
        }

        app(NotificationPreferencesService::class)->updateForUser($user, $toSave);
        session()->flash('message', 'Notification preferences saved.');
    }

    public function render()
    {
        return view('livewire.shared.notification-preferences-page', [
            'availableChannels' => NotificationPreferencesService::availableChannels(),
            'typeLabels' => [
                'experiment.stuck' => 'Experiment stuck / recovery failed',
                'experiment.completed' => 'Experiment completed',
                'experiment.budget.warning' => 'Experiment budget warning',
                'project.run.failed' => 'Project run failed',
                'project.run.completed' => 'Project run completed',
                'project.budget.warning' => 'Project budget warning',
                'project.milestone.reached' => 'Project milestone reached',
                'agent.risk.high' => 'Agent high-risk auto-disabled',
                'approval.requested' => 'Approval requested',
                'approval.escalated' => 'Approval escalated',
                'human_task.sla_breached' => 'Human task SLA breached',
                'budget.exceeded' => 'Budget exceeded',
                'crew.execution.completed' => 'Crew execution completed',
                'usage.alert' => 'Usage limit alert',
                'weekly.digest' => 'Weekly digest email',
                'system.deploy' => 'System deployment notifications',
            ],
            'channelLabels' => [
                'in_app' => 'In-App',
                'mail' => 'Email',
                'push' => 'Push',
            ],
        ])->layout('layouts.app', ['header' => 'Notification Preferences']);
    }
}
