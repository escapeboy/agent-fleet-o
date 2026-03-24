<?php

namespace App\Domain\Shared\Notifications;

use App\Domain\Integration\Models\Integration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IntegrationRequiresReauthNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Integration $integration,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->integration->getAttribute('name');
        $driver = (string) $this->integration->getAttribute('driver');
        $integrationId = $this->integration->getKey();

        return (new MailMessage)
            ->subject("Re-authorization required: {$name}")
            ->greeting('Action required')
            ->line("Your **{$name}** integration ({$driver}) has lost access and needs to be re-authorized.")
            ->line('This typically happens when the access token has been revoked or expired beyond the point of automatic renewal.')
            ->action('Re-authorize Integration', url("/integrations/{$integrationId}"))
            ->line('Until re-authorized, this integration will not be able to execute actions or sync data.');
    }
}
