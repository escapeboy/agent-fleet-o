<?php

namespace App\Domain\Project\Notifications;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectRunCompletedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Project $project,
        public readonly ProjectRun $run,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = $this->project->notification_config['channels'] ?? ['database'];

        return array_intersect($channels, ['database', 'mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Run #{$this->run->run_number} completed — {$this->project->title}")
            ->line("Project \"{$this->project->title}\" — Run #{$this->run->run_number} completed successfully.")
            ->action('View Project', route('projects.show', $this->project));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'project.run.completed',
            'project_id' => $this->project->id,
            'project_title' => $this->project->title,
            'run_id' => $this->run->id,
            'run_number' => $this->run->run_number,
            'message' => "Project \"{$this->project->title}\" — Run #{$this->run->run_number} completed",
        ];
    }
}
