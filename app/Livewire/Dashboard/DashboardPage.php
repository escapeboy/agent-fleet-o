<?php

namespace App\Livewire\Dashboard;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use Livewire\Component;

class DashboardPage extends Component
{
    public function render()
    {
        $terminalStatuses = [
            ExperimentStatus::Completed,
            ExperimentStatus::Killed,
            ExperimentStatus::Discarded,
            ExperimentStatus::Expired,
        ];

        $total = Experiment::count();
        $completed = Experiment::where('status', ExperimentStatus::Completed)->count();
        $active = Experiment::whereNotIn('status', array_map(fn ($s) => $s->value, $terminalStatuses))
            ->where('status', '!=', ExperimentStatus::Draft)
            ->count();
        $pendingApprovals = ApprovalRequest::where('status', 'pending')->count();
        $successRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;
        $totalSpend = CreditLedger::where('type', 'spend')->sum('amount');

        // Skills & Agents KPIs
        $activeSkills = Skill::where('status', 'active')->count();
        $activeAgents = Agent::where('status', 'active')->count();
        $skillExecutions24h = SkillExecution::where('created_at', '>=', now()->subDay())->count();
        $agentRuns24h = AgentExecution::where('created_at', '>=', now()->subDay())->count();

        // Projects KPIs
        $activeProjects = Project::where('status', ProjectStatus::Active)->count();
        $projectRuns24h = ProjectRun::where('created_at', '>=', now()->subDay())->count();

        // Active experiments (top 10)
        $activeExperiments = Experiment::whereNotIn('status', array_map(fn ($s) => $s->value, $terminalStatuses))
            ->where('status', '!=', ExperimentStatus::Draft)
            ->latest('updated_at')
            ->limit(10)
            ->get();

        // Alerts
        $alerts = $this->gatherAlerts();

        return view('livewire.dashboard.dashboard-page', [
            'active' => $active,
            'completed' => $completed,
            'total' => $total,
            'pendingApprovals' => $pendingApprovals,
            'successRate' => $successRate,
            'totalSpend' => abs($totalSpend),
            'activeSkills' => $activeSkills,
            'activeAgents' => $activeAgents,
            'skillExecutions24h' => $skillExecutions24h,
            'agentRuns24h' => $agentRuns24h,
            'activeProjects' => $activeProjects,
            'projectRuns24h' => $projectRuns24h,
            'activeExperiments' => $activeExperiments,
            'alerts' => $alerts,
        ])->layout('layouts.app', ['header' => 'Dashboard']);
    }

    private function gatherAlerts(): array
    {
        $alerts = [];

        // Budget warnings: experiments over 80% budget
        $budgetWarnings = Experiment::whereColumn('budget_spent_credits', '>', \DB::raw('budget_cap_credits * 0.8'))
            ->where('budget_cap_credits', '>', 0)
            ->whereNotIn('status', ['completed', 'killed', 'discarded', 'expired'])
            ->get();

        foreach ($budgetWarnings as $exp) {
            $pct = round(($exp->budget_spent_credits / $exp->budget_cap_credits) * 100);
            $alerts[] = [
                'type' => 'warning',
                'message' => "Run \"{$exp->title}\" at {$pct}% budget",
                'link' => route('experiments.show', $exp),
            ];
        }

        // Failed experiments in last 24h
        $failedStatuses = [
            ExperimentStatus::ScoringFailed,
            ExperimentStatus::PlanningFailed,
            ExperimentStatus::BuildingFailed,
            ExperimentStatus::ExecutionFailed,
        ];
        $recentFailed = Experiment::whereIn('status', $failedStatuses)
            ->where('updated_at', '>=', now()->subDay())
            ->get();

        foreach ($recentFailed as $exp) {
            $alerts[] = [
                'type' => 'error',
                'message' => "Run \"{$exp->title}\" failed ({$exp->status->value})",
                'link' => route('experiments.show', $exp),
            ];
        }

        // Stale approvals expiring within 6h
        $stalePending = ApprovalRequest::where('status', 'pending')
            ->where('expires_at', '<=', now()->addHours(6))
            ->where('expires_at', '>', now())
            ->with('experiment')
            ->get();

        foreach ($stalePending as $approval) {
            $alerts[] = [
                'type' => 'warning',
                'message' => "Approval for \"{$approval->experiment->title}\" expires {$approval->expires_at->diffForHumans()}",
                'link' => route('approvals.index'),
            ];
        }

        return $alerts;
    }
}
