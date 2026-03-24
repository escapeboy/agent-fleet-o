<?php

namespace App\Domain\Shared\Jobs;

use App\Domain\Shared\Notifications\InlinePushNotification;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\WebPushMessage;

class SendPushNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(
        public readonly string $userId,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $actionUrl,
        public readonly string $type,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $user = User::find($this->userId);

        if (! $user || $user->pushSubscriptions()->doesntExist()) {
            return;
        }

        $message = (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge-72x72.png')
            ->data(['url' => $this->actionUrl ?? '/notifications', 'type' => $this->type]);

        if ($this->actionUrl) {
            $message->action('View', $this->actionUrl);
        }

        try {
            $user->notify(new InlinePushNotification($message));
        } catch (\Throwable $e) {
            Log::warning('SendPushNotificationJob: push delivery failed', [
                'user_id' => $this->userId,
                'type' => $this->type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function backoff(): array
    {
        return [60, 300];
    }
}
