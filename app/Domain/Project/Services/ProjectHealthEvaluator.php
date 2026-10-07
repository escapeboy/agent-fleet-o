<?php

namespace App\Domain\Project\Services;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Models\Credential;
use App\Domain\Crew\Models\Crew;
use App\Domain\Project\DTOs\ProjectHealthReason;
use App\Domain\Project\DTOs\ProjectHealthReport;
use App\Domain\Project\Enums\ProjectHealthState;
use App\Domain\Project\Enums\ProjectRunStatus;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Project\Models\ProjectSchedule;
use App\Domain\Workflow\Models\WorkflowNode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * Read-only diagnosis of why a project is (or is not) running. No writes, no queue.
 */
class ProjectHealthEvaluator
{
    public const SCHEDULE_OVERDUE_MINUTES = 15;

    public const RUN_STUCK_MINUTES = 30;

    public const RECENT_RUNS_WINDOW = 5;

    public const CREDENTIAL_EXPIRY_DAYS = 7;

    public const ERROR_MAX_LENGTH = 200;

    public function evaluate(Project $project): ProjectHealthReport
    {
        $now = CarbonImmutable::now();

        if ($off = $this->offReason($project)) {
            return new ProjectHealthReport(ProjectHealthState::Off, [$off], $now);
        }

        $reasons = array_values(array_filter([
            $this->budgetReason($project),
            $this->failedReason($project),
            $this->creditsReason($project),
            $this->scheduleReason($project, $now),
            $this->stuckRunReason($project, $now),
            $this->recentFailuresReason($project),
            $this->agentReason($project),
            ...$this->credentialReasons($project, $now),
        ]));

        $state = ProjectHealthState::Ok;
        foreach ($reasons as $reason) {
            if ($reason->severity->rank() > $state->rank()) {
                $state = $reason->severity;
            }
        }

        return new ProjectHealthReport($state, $reasons, $now);
    }

    private function status(Project $project): ProjectStatus
    {
        /** @var ProjectStatus $status */
        $status = $project->getAttribute('status');

        return $status;
    }

    private function offReason(Project $project): ?ProjectHealthReason
    {
        if (in_array($this->status($project), [ProjectStatus::Draft, ProjectStatus::Completed, ProjectStatus::Archived], true)) {
            return new ProjectHealthReason(
                'project_inactive',
                ProjectHealthState::Off,
                "Project is {$this->status($project)->value} and is not expected to run.",
                $this->status($project) === ProjectStatus::Draft ? 'Activate the project when it is ready.' : null,
            );
        }

        if ($this->status($project) === ProjectStatus::Paused && ! $this->pausedForBudget($project)) {
            return new ProjectHealthReason(
                'paused_manual',
                ProjectHealthState::Off,
                'Project was paused manually.',
                'Resume the project.',
            );
        }

        return null;
    }

    private function pausedReason(Project $project): ?string
    {
        $activity = Activity::query()
            ->where('subject_type', $project->getMorphClass())
            ->where('subject_id', $project->id)
            ->where('description', 'project.paused')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $reason = $activity?->properties->get('reason');

        return is_string($reason) ? $reason : null;
    }

    private function pausedForBudget(Project $project): bool
    {
        return $this->status($project) === ProjectStatus::Paused
            && str_starts_with((string) $this->pausedReason($project), 'Budget');
    }

    private function budgetReason(Project $project): ?ProjectHealthReason
    {
        $overPeriod = null;
        foreach (['daily', 'weekly', 'monthly'] as $period) {
            if ($project->isOverBudget($period)) {
                $overPeriod = $period;
                break;
            }
        }

        if (! $this->pausedForBudget($project) && $overPeriod === null) {
            return null;
        }

        $message = $overPeriod !== null
            ? "The {$overPeriod} budget cap has been reached."
            : 'Project was paused because a budget cap was exceeded.';

        return new ProjectHealthReason(
            'paused_budget',
            ProjectHealthState::Stopped,
            $message,
            'Raise the budget cap or wait for the next budget period, then resume the project.',
        );
    }

    private function failedReason(Project $project): ?ProjectHealthReason
    {
        if ($this->status($project) !== ProjectStatus::Failed) {
            return null;
        }

        $failures = $project->consecutiveFailures();
        $message = "Project failed after {$failures} consecutive failed run(s).";

        $error = $project->runs()->where('status', 'failed')->whereNotNull('error_message')->value('error_message');
        if (is_string($error) && $error !== '') {
            $message .= ' Last error: '.mb_substr($error, 0, self::ERROR_MAX_LENGTH);
        }

        return new ProjectHealthReason(
            'failed_consecutive',
            ProjectHealthState::Stopped,
            $message,
            'Fix the cause of the failures, then restart the project.',
        );
    }

    private function creditsReason(Project $project): ?ProjectHealthReason
    {
        if (! CreditLedger::teamHasPurchasedCredits($project->team_id)) {
            return null;
        }

        $balance = CreditLedger::withoutGlobalScopes()
            ->where('team_id', $project->team_id)
            ->orderByDesc('created_at')
            ->value('balance_after') ?? 0;

        if ($balance > 0) {
            return null;
        }

        return new ProjectHealthReason(
            'team_out_of_credits',
            ProjectHealthState::Stopped,
            'The team has no remaining credits.',
            'Purchase more credits.',
        );
    }

    private function scheduleReason(Project $project, CarbonImmutable $now): ?ProjectHealthReason
    {
        /** @var ProjectSchedule|null $schedule */
        $schedule = $project->schedule;
        if ($this->status($project) !== ProjectStatus::Active || ! $schedule) {
            return null;
        }

        if (! $schedule->enabled) {
            return new ProjectHealthReason(
                'schedule_disabled',
                ProjectHealthState::Degraded,
                'The schedule is disabled, so no runs will start on their own.',
                'Re-enable the schedule.',
            );
        }

        $nextRunAt = $schedule->getAttribute('next_run_at');
        if ($nextRunAt instanceof DateTimeInterface && CarbonImmutable::instance($nextRunAt)->lt($now->subMinutes(self::SCHEDULE_OVERDUE_MINUTES))) {
            return new ProjectHealthReason(
                'schedule_overdue',
                ProjectHealthState::Degraded,
                'The next run was due at '.CarbonImmutable::instance($nextRunAt)->toIso8601String().' but has not started.',
                'Check that the scheduler and queue workers are running.',
            );
        }

        return null;
    }

    private function stuckRunReason(Project $project, CarbonImmutable $now): ?ProjectHealthReason
    {
        $cutoff = $now->subMinutes(self::RUN_STUCK_MINUTES);

        /** @var ProjectRun|null $stuck */
        $stuck = $project->runs()
            ->whereIn('status', ['running', 'pending'])
            ->whereRaw('coalesce(started_at, created_at) < ?', [$cutoff])
            ->first();

        if (! $stuck) {
            return null;
        }

        return new ProjectHealthReason(
            'run_stuck',
            ProjectHealthState::Degraded,
            'Run #'.$stuck->getAttribute('run_number').' has not finished after more than '.self::RUN_STUCK_MINUTES.' minutes.',
            'Check the queue workers, or cancel the run and trigger a new one.',
        );
    }

    private function recentFailuresReason(Project $project): ?ProjectHealthReason
    {
        if ($this->status($project) === ProjectStatus::Failed) {
            return null;
        }

        $failed = $project->runs()
            ->limit(self::RECENT_RUNS_WINDOW)
            ->get()
            ->filter(fn (Model $run): bool => $run->getAttribute('status') === ProjectRunStatus::Failed)
            ->count();

        if ($failed === 0) {
            return null;
        }

        return new ProjectHealthReason(
            'recent_failures',
            ProjectHealthState::Degraded,
            "{$failed} of the last ".self::RECENT_RUNS_WINDOW.' runs failed.',
            'Open the failed runs and fix the cause before the project is marked failed.',
        );
    }

    private function agentReason(Project $project): ?ProjectHealthReason
    {
        $ids = $this->referencedAgentIds($project);
        if ($ids === []) {
            return null;
        }

        $agents = Agent::withoutGlobalScopes()
            ->where('team_id', $project->team_id)
            ->whereIn('id', $ids)
            ->get();

        if ($agents->isEmpty()) {
            return null;
        }

        $bad = $agents->filter(fn (Agent $a) => in_array($a->status, [AgentStatus::Disabled, AgentStatus::Offline, AgentStatus::Degraded], true));
        if ($bad->isEmpty()) {
            return null;
        }

        $all = $bad->count() === $agents->count();
        $list = $bad->map(fn (Agent $a) => "{$a->name} ({$a->status->value}, last updated {$a->updated_at?->toIso8601String()})")->implode('; ');

        return new ProjectHealthReason(
            'agent_unavailable',
            $all ? ProjectHealthState::Stopped : ProjectHealthState::Degraded,
            ($all ? 'All agents used by this project are unavailable: ' : 'Some agents used by this project are unavailable: ').$list.'. The status may be stale.',
            'Re-enable or replace the unavailable agent(s).',
        );
    }

    /**
     * @return array<int, string>
     */
    private function referencedAgentIds(Project $project): array
    {
        $ids = [];

        $config = $project->getAttribute('agent_config');
        $lead = is_array($config) ? ($config['lead_agent_id'] ?? null) : null;
        if (is_string($lead) && $lead !== '') {
            $ids[] = $lead;
        }

        if ($project->crew_id) {
            $crew = Crew::withoutGlobalScopes()
                ->where('team_id', $project->team_id)
                ->with('members')
                ->find($project->crew_id);
            if ($crew) {
                $ids[] = $crew->coordinator_agent_id;
                $ids[] = $crew->qa_agent_id;
                foreach ($crew->members as $member) {
                    $ids[] = $member->getAttribute('agent_id');
                }
            }
        }

        if ($project->workflow_id) {
            $ids = array_merge($ids, WorkflowNode::withoutGlobalScopes()
                ->where('workflow_id', $project->workflow_id)
                ->whereNotNull('agent_id')
                ->pluck('agent_id')
                ->all());
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @return array<int, ProjectHealthReason>
     */
    private function credentialReasons(Project $project, CarbonImmutable $now): array
    {
        $ids = array_values(array_filter((array) ($project->getAttribute('allowed_credential_ids') ?? [])));
        if ($ids === []) {
            return [];
        }

        $credentials = Credential::withoutGlobalScopes()
            ->where('team_id', $project->team_id)
            ->whereIn('id', $ids)
            ->get();

        $unusable = [];
        $expiring = [];
        foreach ($credentials as $credential) {
            if ($credential->status !== CredentialStatus::Active || ($credential->expires_at && $credential->expires_at->lt($now))) {
                $unusable[] = $credential;
            } elseif ($credential->expires_at && $credential->expires_at->lt($now->addDays(self::CREDENTIAL_EXPIRY_DAYS))) {
                $expiring[] = $credential;
            }
        }

        $reasons = [];
        if ($unusable !== []) {
            $names = implode(', ', array_map(fn ($c) => $c->name, $unusable));
            $reasons[] = new ProjectHealthReason(
                'credential_unusable',
                ProjectHealthState::Degraded,
                "Credential(s) cannot be used (disabled, pending review or expired): {$names}.",
                "Rotate or re-enable credential {$unusable[0]->name}.",
            );
        }
        if ($expiring !== []) {
            $names = implode(', ', array_map(fn ($c) => $c->name, $expiring));
            $reasons[] = new ProjectHealthReason(
                'credential_expiring',
                ProjectHealthState::Degraded,
                'Credential(s) expire within '.self::CREDENTIAL_EXPIRY_DAYS." days: {$names}.",
                "Rotate credential {$expiring[0]->name} before it expires.",
            );
        }

        return $reasons;
    }
}
