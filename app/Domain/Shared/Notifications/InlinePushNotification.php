<?php

namespace App\Domain\Shared\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class InlinePushNotification extends Notification
{
    public function __construct(
        private readonly WebPushMessage $message,
    ) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return $this->message;
    }
}
