<?php

namespace App\Domain\Project\Notifications;

use App\Domain\Project\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ProjectDigestNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Project $project,
        public readonly array $summary,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'project.digest',
            'project_id' => $this->project->id,
            'project_title' => $this->project->title,
            'total_runs' => $this->summary['total_runs'] ?? 0,
            'successful_runs' => $this->summary['successful_runs'] ?? 0,
            'failed_runs' => $this->summary['failed_runs'] ?? 0,
            'total_spend' => $this->summary['total_spend'] ?? 0,
            'milestones_reached' => $this->summary['milestones_reached'] ?? 0,
            'message' => $this->buildMessage(),
        ];
    }

    private function buildMessage(): string
    {
        $total = $this->summary['total_runs'] ?? 0;
        $success = $this->summary['successful_runs'] ?? 0;
        $failed = $this->summary['failed_runs'] ?? 0;
        $spend = $this->summary['total_spend'] ?? 0;

        return "Project \"{$this->project->title}\": {$total} runs ({$success} success, {$failed} failed), {$spend} credits spent";
    }
}
