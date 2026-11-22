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
    case AgentSessionCompleted = 'agent.session.completed';
    case AgentSessionFailed = 'agent.session.failed';
    case AgentSessionCancelled = 'agent.session.cancelled';
    case AgentSessionNeedsInput = 'agent.session.needs_input';
    case AuditEntryCreated = 'audit.entry.created';

    public function label(): string
    {
        return match ($this) {
            self::ExperimentCompleted => 'Experiment Completed',
            self::ExperimentFailed => 'Experiment Failed',
            self::ProjectRunCompleted => 'Project Run Completed',
            self::ProjectRunFailed => 'Project Run Failed',
            self::ApprovalPending => 'Approval Pending',
            self::BudgetWarning => 'Budget Warning',
            self::AgentSessionCompleted => 'Agent Session Completed',
            self::AgentSessionFailed => 'Agent Session Failed',
            self::AgentSessionCancelled => 'Agent Session Cancelled',
            self::AgentSessionNeedsInput => 'Agent Session Needs Input',
            self::AuditEntryCreated => 'Audit Entry Created (SIEM stream)',
        };
    }

    /**
     * High-volume events that an endpoint must name explicitly; the '*'
     * wildcard does not include them.
     */
    public function requiresExplicitSubscription(): bool
    {
        return $this === self::AuditEntryCreated;
    }
}
