<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Models\UserNotification;
use App\Domain\Shared\Services\NotificationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class NotificationTool extends Tool
{
    protected string $name = 'notification_manage';

    protected string $description = 'List unread notifications, send a new in-app notification to the team, or mark notifications as read.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('One of: list_unread | send | mark_read | mark_all_read')
                ->enum(['list_unread', 'send', 'mark_read', 'mark_all_read'])
                ->required(),
            'notification_id' => $schema->string()
                ->description('Required for mark_read. The notification UUID to mark as read.'),
            'title' => $schema->string()
                ->description('Required for send. Notification title.'),
            'body' => $schema->string()
                ->description('Required for send. Notification body text.'),
            'type' => $schema->string()
                ->description('For send. Notification type (e.g. agent_alert, budget_warning, info).'),
            'action_url' => $schema->string()
                ->description('For send. Optional URL the user can click to navigate.'),
            'user_id' => $schema->string()
                ->description('For send. Target user ID. If omitted, notifies all team members.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => 'required|in:list_unread,send,mark_read,mark_all_read',
            'notification_id' => 'nullable|string',
            'title' => 'nullable|string|max:200',
            'body' => 'nullable|string|max:1000',
            'type' => 'nullable|string|max:50',
            'action_url' => 'nullable|url',
            'user_id' => 'nullable|string',
        ]);

        $user = auth()->user();
        $team = $user?->currentTeam;

        if (! $user || ! $team) {
            return Response::error('No authenticated user or team context.');
        }

        $service = app(NotificationService::class);

        return match ($validated['action']) {
            'list_unread' => $this->listUnread($user->id, $team->id),
            'send' => $this->send($validated, $team->id, $user->id, $service),
            'mark_read' => $this->markRead($validated['notification_id'] ?? null, $user->id),
            'mark_all_read' => $this->markAllRead($user->id, $team->id, $service),
        };
    }

    private function listUnread(string $userId, string $teamId): Response
    {
        $notifications = UserNotification::where('user_id', $userId)
            ->where('team_id', $teamId)
            ->whereNull('read_at')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'type', 'title', 'body', 'action_url', 'created_at']);

        return Response::text(json_encode([
            'unread_count' => $notifications->count(),
            'notifications' => $notifications->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'action_url' => $n->action_url,
                'created_at' => $n->created_at->toIso8601String(),
            ]),
        ]));
    }

    private function send(array $data, string $teamId, string $currentUserId, NotificationService $service): Response
    {
        if (empty($data['title']) || empty($data['body'])) {
            return Response::error('title and body are required for send action.');
        }

        if (! empty($data['user_id'])) {
            $notification = $service->notify(
                userId: $data['user_id'],
                teamId: $teamId,
                type: $data['type'] ?? 'agent_info',
                title: $data['title'],
                body: $data['body'],
                actionUrl: $data['action_url'] ?? null,
            );

            return Response::text(json_encode([
                'success' => true,
                'notification_id' => $notification->id,
            ]));
        }

        $notifications = $service->notifyTeam(
            teamId: $teamId,
            type: $data['type'] ?? 'agent_info',
            title: $data['title'],
            body: $data['body'],
            actionUrl: $data['action_url'] ?? null,
        );

        return Response::text(json_encode([
            'success' => true,
            'notified_count' => $notifications->count(),
        ]));
    }

    private function markRead(?string $notificationId, string $userId): Response
    {
        if (! $notificationId) {
            return Response::error('notification_id is required for mark_read action.');
        }

        $notification = UserNotification::find($notificationId);
        if (! $notification || $notification->user_id !== $userId) {
            return Response::error('Notification not found.');
        }

        $notification->markAsRead();

        return Response::text(json_encode(['success' => true, 'notification_id' => $notificationId]));
    }

    private function markAllRead(string $userId, string $teamId, NotificationService $service): Response
    {
        $service->markAllRead($userId, $teamId);

        return Response::text(json_encode(['success' => true]));
    }
}
