<?php

namespace App\Domain\Shared\Notifications;

use App\Domain\Shared\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Team $team,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to Agent Fleet!')
            ->greeting("Welcome, {$notifiable->name}!")
            ->line("Your team **{$this->team->name}** is ready. Here's how to get started:")
            ->line('1. Connect your AI provider (OpenAI, Anthropic, etc.)')
            ->line('2. Set up your first signal connector')
            ->line('3. Create your first experiment')
            ->action('Go to Dashboard', url('/dashboard'))
            ->line('Need help? Check out our [API documentation](' . url('/docs') . ').');
    }
}
