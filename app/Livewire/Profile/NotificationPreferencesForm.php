<?php

namespace App\Livewire\Profile;

use App\Domain\Shared\Services\NotificationPreferencesService;
use Livewire\Component;

class NotificationPreferencesForm extends Component
{
    /** @var array<string, array<string, bool>> */
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
        $user = auth()->user();

        $endpoint = $payload['endpoint'] ?? '';

        if (! $user || empty($endpoint)) {
            return;
        }

        if (! filter_var($endpoint, FILTER_VALIDATE_URL) || ! str_starts_with($endpoint, 'https://') || strlen($endpoint) > 2048) {
            return;
        }

        $user->updatePushSubscription(
            endpoint: $endpoint,
            key: $payload['keys']['p256dh'] ?? null,
            token: $payload['keys']['auth'] ?? null,
            contentEncoding: 'aesgcm',
        );

        $this->pushStatus = 'subscribed';
    }

    public function deletePushSubscription(string $endpoint): void
    {
        auth()->user()?->deletePushSubscription($endpoint);
        $this->pushStatus = 'unsubscribed';
    }

    public function save(): void
    {
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
        session()->flash('notifications_saved', true);
    }

    public function render()
    {
        return view('livewire.profile.notification-preferences-form', [
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
            ],
            'channelLabels' => [
                'in_app' => 'In-App',
                'mail' => 'Email',
                'push' => 'Push',
            ],
        ]);
    }
}
