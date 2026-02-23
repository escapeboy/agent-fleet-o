<?php

namespace App\Livewire\Shared;

use App\Domain\Shared\Models\UserNotification;
use App\Domain\Shared\Services\NotificationService;
use Livewire\Component;

class NotificationBell extends Component
{
    public bool $open = false;

    public function markAllRead(): void
    {
        $user = auth()->user();
        $team = $user?->currentTeam;
        if (! $user || ! $team) {
            return;
        }

        app(NotificationService::class)->markAllRead($user->id, $team->id);
    }

    public function markRead(string $id): void
    {
        $notification = UserNotification::find($id);
        if ($notification && $notification->user_id === auth()->id()) {
            $notification->markAsRead();
        }
    }

    public function render()
    {
        $user = auth()->user();
        $team = $user?->currentTeam;

        $unreadCount = 0;
        $notifications = collect();

        if ($user && $team) {
            $unreadCount = app(NotificationService::class)->unreadCount($user->id, $team->id);
            $notifications = UserNotification::where('user_id', $user->id)
                ->where('team_id', $team->id)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();
        }

        return view('livewire.shared.notification-bell', compact('unreadCount', 'notifications'));
    }
}
