<?php

namespace App\Livewire\Dashboard;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Budget\Services\SpendForecaster;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Skill\Models\Skill;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Skill\Models\SkillExecution;
use Illuminate\Support\Facades\Cache;
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

        // Cache aggregate KPIs per team for 30s — avoids 10+ COUNT queries on every wire:poll.5s tick.
        // Key MUST be team-scoped to prevent cross-tenant data leaks in multi-team deployments.
        $teamId = auth()->user()->current_team_id;

        $kpis = Cache::remember("dashboard.kpis:{$teamId}", 30, function () use ($terminalStatuses) {
            $total = Experiment::count();
            $completed = Experiment::where('status', ExperimentStatus::Completed)->count();

            return [
                'total' => $total,
                'completed' => $completed,
                'active' => Experiment::whereNotIn('status', array_map(fn ($s) => $s->value, $terminalStatuses))
                    ->where('status', '!=', ExperimentStatus::Draft)
                    ->count(),
                'pendingApprovals' => ApprovalRequest::where('status', 'pending')->count(),
                'successRate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
                'totalSpend' => abs((float) CreditLedger::where('type', 'spend')->sum('amount')),
                'activeSkills' => Skill::where('status', 'active')->count(),
                'activeAgents' => Agent::where('status', 'active')->count(),
                'skillExecutions24h' => SkillExecution::where('created_at', '>=', now()->subDay())->count(),
                'agentRuns24h' => AgentExecution::where('created_at', '>=', now()->subDay())->count(),
                'activeProjects' => Project::where('status', ProjectStatus::Active)->count(),
                'projectRuns24h' => ProjectRun::where('created_at', '>=', now()->subDay())->count(),
                'spendForecast' => app(SpendForecaster::class)->forecast(),
            ];
        });

        // Active experiments list — short TTL so it's near-real-time
        $activeExperiments = Cache::remember("dashboard.active_experiments:{$teamId}", 10, function () use ($terminalStatuses) {
            return Experiment::whereNotIn('status', array_map(fn ($s) => $s->value, $terminalStatuses))
                ->where('status', '!=', ExperimentStatus::Draft)
                ->latest('updated_at')
                ->limit(10)
                ->get();
        });

        $alerts = $this->gatherAlerts();

        $hasProviderKeys = Cache::remember("dashboard.has_provider_keys:{$teamId}", 60, function () use ($teamId) {
            return TeamProviderCredential::where('team_id', $teamId)
                ->where('is_active', true)
                ->exists();
        });

        return view('livewire.dashboard.dashboard-page', array_merge($kpis, [
            'activeExperiments' => $activeExperiments,
            'alerts' => $alerts,
            'hasProviderKeys' => $hasProviderKeys,
        ]))->layout('layouts.app', ['header' => 'Dashboard']);
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
