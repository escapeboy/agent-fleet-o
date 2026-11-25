<?php

namespace App\Domain\Crew\Notifications;

use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class StuckCrewExecutionNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly CrewExecution $execution,
        public readonly CrewTaskExecution $task,
        public readonly int $recoveryAttempts,
        public readonly string $stuckDuration,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if ($notifiable instanceof User
            && $notifiable->prefersChannel('crew.stuck', 'push')
            && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        $url = route('crews.show', $this->execution->crew_id);

        return (new WebPushMessage)
            ->title("Crew run paused: {$this->task->title}")
            ->body("Worker stopped responding for {$this->stuckDuration}. Paused after {$this->recoveryAttempts} recovery attempt(s); resume it manually.")
            ->icon('/favicon.ico')
            ->action('View Crew', $url)
            ->data(['url' => $url, 'type' => 'crew.stuck']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject("Crew run paused — task \"{$this->task->title}\" stuck")
            ->line("Task \"{$this->task->title}\" stopped responding for {$this->stuckDuration}.")
            ->line("The crew run was paused after {$this->recoveryAttempts} recovery attempt(s).")
            ->line('Resume the run manually to continue.')
            ->action('View Crew', route('crews.show', $this->execution->crew_id));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'crew.stuck',
            'crew_execution_id' => $this->execution->id,
            'crew_id' => $this->execution->crew_id,
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'recovery_attempts' => $this->recoveryAttempts,
            'stuck_duration' => $this->stuckDuration,
            'message' => "Crew run paused after {$this->recoveryAttempts} recovery attempts: task \"{$this->task->title}\" stopped responding",
        ];
    }
}
