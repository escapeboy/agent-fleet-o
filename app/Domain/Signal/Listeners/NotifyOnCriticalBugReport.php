<?php

namespace App\Domain\Signal\Listeners;

use App\Domain\Signal\Events\SignalIngested;
use App\Domain\Shared\Services\NotificationService;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotifyOnCriticalBugReport
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function handle(SignalIngested $event): void
    {
        $signal = $event->signal;

        if ($signal->source_type !== 'bug_report') {
            return;
        }

        $severity = $signal->payload['severity'] ?? null;

        if ($severity !== 'critical') {
            return;
        }

        $title = $signal->payload['title'] ?? 'Bug report';
        $project = $signal->project_key ?? $signal->payload['project'] ?? 'unknown';

        try {
            $owners = User::whereHas('teams', fn ($q) => $q->where('teams.id', $signal->team_id)
                ->whereIn('team_user.role', ['owner', 'admin'])
            )->get();

            foreach ($owners as $owner) {
                $this->notifications->notify(
                    userId: $owner->id,
                    teamId: $signal->team_id,
                    type: 'bug_report_critical',
                    title: '🔴 Critical Bug Report',
                    body: "[{$project}] {$title}",
                    actionUrl: '/bug-reports/'.$signal->id,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('NotifyOnCriticalBugReport: failed to notify', ['error' => $e->getMessage()]);
        }
    }
}
