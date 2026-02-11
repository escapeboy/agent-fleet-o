<?php

namespace App\Domain\Project\Notifications;

use App\Domain\Project\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ProjectBudgetWarningNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Project $project,
        public readonly string $period,
        public readonly int $currentSpend,
        public readonly int $cap,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $percentage = round(($this->currentSpend / $this->cap) * 100);

        return [
            'type' => 'project.budget.warning',
            'project_id' => $this->project->id,
            'project_title' => $this->project->title,
            'period' => $this->period,
            'current_spend' => $this->currentSpend,
            'cap' => $this->cap,
            'percentage' => $percentage,
            'message' => "Project \"{$this->project->title}\" has used {$percentage}% of its {$this->period} budget ({$this->currentSpend}/{$this->cap})",
        ];
    }
}
