<?php

namespace App\Mcp\Tools\Shared;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use NotificationChannels\WebPush\PushSubscription;

class PushSubscriptionManageTool extends Tool
{
    protected string $name = 'push_subscription_manage';

    protected string $description = 'List or delete PWA push subscriptions for the current user.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('One of: list | delete')
                ->enum(['list', 'delete'])
                ->required(),
            'endpoint' => $schema->string()
                ->description('Required for delete. The push subscription endpoint URL to remove.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => 'required|in:list,delete',
            'endpoint' => 'nullable|string',
        ]);

        $user = auth()->user();
        if (! $user) {
            return Response::error('No authenticated user.');
        }

        return match ($validated['action']) {
            'list' => $this->list($user),
            'delete' => $this->delete($user, $validated['endpoint'] ?? null),
        };
    }

    private function list(User $user): Response
    {
        $subscriptions = PushSubscription::where('subscribable_type', $user->getMorphClass())
            ->where('subscribable_id', $user->getKey())
            ->get(['id', 'endpoint', 'content_encoding', 'created_at', 'updated_at']);

        return Response::text(json_encode([
            'count' => $subscriptions->count(),
            'subscriptions' => $subscriptions->map(fn ($s) => [
                'id' => $s->id,
                'endpoint' => $s->endpoint,
                'content_encoding' => $s->content_encoding,
                'created_at' => $s->created_at?->toIso8601String(),
                'updated_at' => $s->updated_at?->toIso8601String(),
            ]),
        ]));
    }

    private function delete(User $user, ?string $endpoint): Response
    {
        if (! $endpoint) {
            return Response::error('endpoint is required for delete action.');
        }

        $deleted = PushSubscription::where('subscribable_type', $user->getMorphClass())
            ->where('subscribable_id', $user->getKey())
            ->where('endpoint', $endpoint)
            ->delete();

        return Response::text(json_encode([
            'success' => (bool) $deleted,
            'deleted_count' => $deleted,
        ]));
    }
}
