<?php

namespace App\Domain\Experiment\Notifications;

use App\Domain\Experiment\Models\Experiment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StuckExperimentNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Experiment $experiment,
        public readonly int $recoveryAttempts,
        public readonly string $stuckState,
        public readonly string $stuckDuration,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject("Experiment stuck in {$this->stuckState} — {$this->experiment->title}")
            ->line("Experiment \"{$this->experiment->title}\" has been stuck in **{$this->stuckState}** for {$this->stuckDuration}.")
            ->line("Recovery has been attempted {$this->recoveryAttempts} time(s) without success.")
            ->line('Manual intervention may be required.')
            ->action('View Health Dashboard', route('health'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'experiment.stuck',
            'experiment_id' => $this->experiment->id,
            'experiment_title' => $this->experiment->title,
            'stuck_state' => $this->stuckState,
            'stuck_duration' => $this->stuckDuration,
            'recovery_attempts' => $this->recoveryAttempts,
            'message' => "Experiment \"{$this->experiment->title}\" stuck in {$this->stuckState} after {$this->recoveryAttempts} recovery attempts",
        ];
    }
}
