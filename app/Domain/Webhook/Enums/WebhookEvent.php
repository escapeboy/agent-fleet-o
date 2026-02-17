<?php

namespace App\Domain\Webhook\Enums;

enum WebhookEvent: string
{
    case ExperimentCompleted = 'experiment.completed';
    case ExperimentFailed = 'experiment.failed';
    case ProjectRunCompleted = 'project.run.completed';
    case ProjectRunFailed = 'project.run.failed';
    case ApprovalPending = 'approval.pending';
    case BudgetWarning = 'budget.warning';

    public function label(): string
    {
        return match ($this) {
            self::ExperimentCompleted => 'Experiment Completed',
            self::ExperimentFailed => 'Experiment Failed',
            self::ProjectRunCompleted => 'Project Run Completed',
            self::ProjectRunFailed => 'Project Run Failed',
            self::ApprovalPending => 'Approval Pending',
            self::BudgetWarning => 'Budget Warning',
        };
    }
}
