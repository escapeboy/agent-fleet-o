<?php

namespace App\Domain\Project\Notifications;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectRunFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Project $project,
        public readonly ProjectRun $run,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
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
