<?php

namespace App\Domain\Project\Notifications;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class ProjectRunFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Project $project,
        public readonly ProjectRun $run,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = [];

        $configChannels = $this->project->notification_config['channels'] ?? ['mail'];
        if (in_array('mail', $configChannels)) {
            $channels[] = 'mail';
        }

        if ($notifiable instanceof User
            && $notifiable->prefersChannel('project.run.failed', 'push')
            && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels ?: ['mail'];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return WebPushMessage::create()
            ->title("Run #{$this->run->run_number} failed — {$this->project->title}")
            ->body($this->run->error_message ? "Error: {$this->run->error_message}" : 'Project run failed.')
            ->icon('/favicon.ico')
            ->action('View Project', route('projects.show', $this->project))
            ->data(['url' => route('projects.show', $this->project), 'type' => 'project.run.failed']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject("Run #{$this->run->run_number} failed — {$this->project->title}")
            ->line("Project \"{$this->project->title}\" — Run #{$this->run->run_number} has failed.")
            ->line($this->run->error_message ? "Error: {$this->run->error_message}" : '')
            ->action('View Project', route('projects.show', $this->project));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'project.run.failed',
            'project_id' => $this->project->id,
            'project_title' => $this->project->title,
            'run_id' => $this->run->id,
            'run_number' => $this->run->run_number,
            'error' => $this->run->error_message,
            'message' => "Project \"{$this->project->title}\" — Run #{$this->run->run_number} failed",
        ];
    }
}
