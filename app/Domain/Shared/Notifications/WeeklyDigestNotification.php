<?php

namespace App\Domain\Shared\Notifications;

use App\Domain\Shared\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WeeklyDigestNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Team $team,
        public readonly int $experimentsCreated,
        public readonly int $experimentsCompleted,
        public readonly int $outboundSent,
        public readonly int $signalsIngested,
        public readonly int $budgetSpentCents,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $budgetFormatted = '$'.number_format($this->budgetSpentCents / 100, 2);

        return (new MailMessage)
            ->subject("Weekly Digest — {$this->team->name}")
            ->greeting('Your week in review')
            ->line("Here's what happened on **{$this->team->name}** in the last 7 days:")
            ->line("- **{$this->experimentsCreated}** experiments created")
            ->line("- **{$this->experimentsCompleted}** experiments completed")
            ->line("- **{$this->signalsIngested}** signals ingested")
            ->line("- **{$this->outboundSent}** outbound messages sent")
            ->line("- **{$budgetFormatted}** spent on AI calls")
            ->action('View Dashboard', url('/dashboard'));
    }
}
