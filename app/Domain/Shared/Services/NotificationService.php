<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Jobs\SendPushNotificationJob;
use App\Domain\Shared\Models\UserNotification;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationService
{
    /**
     * Create an in-app notification for a user and optionally dispatch a push.
     * Respects the user's notification_preferences.
     */
    public function notify(
        string $userId,
        string $teamId,
        string $type,
        string $title,
        string $body,
        ?string $actionUrl = null,
        ?array $data = null,
    ): ?UserNotification {
        try {
            $user = User::find($userId);
        } catch (\Illuminate\Database\QueryException) {
            return null;
        }

        if (! $user) {
            return null;
        }

        $notification = null;

        if ($user->prefersChannel($type, 'in_app')) {
            $notification = UserNotification::create([
                'user_id' => $userId,
                'team_id' => $teamId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'action_url' => $actionUrl,
                'data' => $data,
            ]);
        }

        if ($user->prefersChannel($type, 'push')) {
            SendPushNotificationJob::dispatch($userId, $title, $body, $actionUrl, $type);
        }

        return $notification;
    }

    /**
     * Notify all members of a team.
     */
    public function notifyTeam(
        string $teamId,
        string $type,
        string $title,
        string $body,
        ?string $actionUrl = null,
        ?array $data = null,
        array $excludeUserIds = [],
    ): Collection {
        $users = User::whereHas('teams', fn ($q) => $q->where('teams.id', $teamId))
            ->when($excludeUserIds, fn ($q) => $q->whereNotIn('id', $excludeUserIds))
            ->get(['id', 'notification_preferences']);

        return $users->map(fn (User $user) => $this->notify(
            userId: $user->id,
            teamId: $teamId,
            type: $type,
            title: $title,
            body: $body,
            actionUrl: $actionUrl,
            data: $data,
        ))->filter();
    }

    public function unreadCount(string $userId, string $teamId): int
    {
        return UserNotification::where('user_id', $userId)
            ->where('team_id', $teamId)
            ->whereNull('read_at')
            ->count();
    }

    public function markAllRead(string $userId, string $teamId): void
    {
        UserNotification::where('user_id', $userId)
            ->where('team_id', $teamId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
