<?php

namespace App\Domain\Shared\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SocialMergeOtpNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $otp,
        private readonly string $providerName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm account link — '.config('app.name'))
            ->greeting('Hello!')
            ->line("Your verification code to link your {$this->providerName} account is:")
            ->line("**{$this->otp}**")
            ->line('This code expires in 10 minutes.')
            ->line('If you did not request this, you can safely ignore this email.');
    }
}
