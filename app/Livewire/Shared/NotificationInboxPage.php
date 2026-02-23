<?php

namespace App\Livewire\Shared;

use App\Domain\Shared\Models\UserNotification;
use App\Domain\Shared\Services\NotificationService;
use Livewire\Component;
use Livewire\WithPagination;

class NotificationInboxPage extends Component
{
    use WithPagination;

    public bool $unreadOnly = false;

    public string $typeFilter = '';

    public function updatedUnreadOnly(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

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

    public function deleteNotification(string $id): void
    {
        $notification = UserNotification::find($id);
        if ($notification && $notification->user_id === auth()->id()) {
            $notification->delete();
        }
    }

    public function render()
    {
        $user = auth()->user();
        $team = $user?->currentTeam;

        $query = UserNotification::where('user_id', $user->id)
            ->where('team_id', $team->id)
            ->orderByDesc('created_at');

        if ($this->unreadOnly) {
            $query->whereNull('read_at');
        }

        if ($this->typeFilter) {
            $query->where('type', $this->typeFilter);
        }

        $types = UserNotification::where('user_id', $user->id)
            ->where('team_id', $team->id)
            ->distinct()
            ->pluck('type');

        $unreadCount = app(NotificationService::class)->unreadCount($user->id, $team->id);

        return view('livewire.shared.notification-inbox-page', [
            'notifications' => $query->paginate(20),
            'types' => $types,
            'unreadCount' => $unreadCount,
        ])->layout('layouts.app', ['header' => 'Notifications']);
    }
}
