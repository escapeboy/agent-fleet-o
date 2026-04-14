<?php

namespace App\Domain\Signal\Listeners;

use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Events\SignalStatusChanged;
use App\Domain\Shared\Services\NotificationService;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotifyOnSignalStatusChange
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function handle(SignalStatusChanged $event): void
    {
        $signal = $event->signal;

        if ($event->newStatus !== SignalStatus::Review) {
            return;
        }

        $title = $signal->payload['title'] ?? 'Bug report';

        try {
            $owners = User::whereHas('teams', fn ($q) => $q->where('teams.id', $signal->team_id)
                ->whereIn('team_user.role', ['owner', 'admin'])
            )->get();

            foreach ($owners as $owner) {
                $this->notifications->notify(
                    userId: $owner->id,
                    teamId: $signal->team_id,
                    type: 'bug_report_review',
                    title: 'Bug Report Ready for Review',
                    body: "Agent fix is ready for: {$title}",
                    actionUrl: '/bug-reports/'.$signal->id,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('NotifyOnSignalStatusChange: failed to notify', ['error' => $e->getMessage()]);
        }
    }
}
