<?php

namespace App\Domain\Project\Notifications;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMilestone;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ProjectMilestoneReachedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Project $project,
        public readonly ProjectMilestone $milestone,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'project.milestone.reached',
            'project_id' => $this->project->id,
            'project_title' => $this->project->title,
            'milestone_id' => $this->milestone->id,
            'milestone_title' => $this->milestone->title,
            'message' => "Milestone \"{$this->milestone->title}\" completed in project \"{$this->project->title}\"",
        ];
    }
}
