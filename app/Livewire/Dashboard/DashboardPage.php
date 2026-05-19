<?php

namespace App\Livewire\Dashboard;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Agent\Models\AiRun;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Budget\Services\SpendForecaster;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotMessage;
use App\Domain\Chatbot\Models\ChatbotSession;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class DashboardPage extends Component
{
    /** @var array<string, bool> */
    public array $widgets = [];

    /**
     * Dashboard persona — borrowed from prilog.ai's CTO/SRE dual-positioning.
     * 'sre' shows the existing operator-focused view (experiments, agents, approvals).
     * 'cto' adds a debt-reduction summary tile aimed at leadership reviews.
     */
    public string $persona = 'sre';

    protected const VALID_PERSONAS = ['sre', 'cto'];

    /** Default widget visibility — all on by default */
    protected const DEFAULT_WIDGETS = [
        'experiments' => true,
        'projects' => true,
        'agents' => true,
        'skills' => true,
        'budget' => true,
        'approvals' => true,
        'activity' => true,
        'chatbots' => true,
    ];

    public function mount(): void
    {
        $team = auth()->user()->currentTeam;
        $saved = $team?->dashboard_config['widgets'] ?? [];
        $this->widgets = array_merge(self::DEFAULT_WIDGETS, $saved);

        $savedPersona = $team?->dashboard_config['persona'] ?? null;
        if (is_string($savedPersona) && in_array($savedPersona, self::VALID_PERSONAS, true)) {
            $this->persona = $savedPersona;
        }
    }

    public function setPersona(string $persona): void
    {
        Gate::authorize('edit-content');

        if (! in_array($persona, self::VALID_PERSONAS, true)) {
            return;
        }

        $this->persona = $persona;

        $team = auth()->user()->currentTeam;
        if (! $team) {
            return;
        }

        $config = $team->dashboard_config ?? [];
        $config['persona'] = $persona;
        $team->update(['dashboard_config' => $config]);
    }

    public function toggleWidget(string $key): void
    {
        Gate::authorize('edit-content');

        if (! array_key_exists($key, self::DEFAULT_WIDGETS)) {
            return;
        }

        $this->widgets[$key] = ! ($this->widgets[$key] ?? true);

        $team = auth()->user()->currentTeam;
        $config = $team->dashboard_config ?? [];
        $config['widgets'] = $this->widgets;
        $team->update(['dashboard_config' => $config]);
    }

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

        $team = auth()->user()->currentTeam;
        $chatbotEnabled = (bool) ($team->settings['chatbot_enabled'] ?? false);

        $chatbotKpis = null;
        if ($chatbotEnabled) {
            $chatbotKpis = Cache::remember("dashboard.chatbot_kpis:{$teamId}", 60, function () {
                return [
                    'active_chatbots' => Chatbot::where('status', 'active')->count(),
                    'sessions_today' => ChatbotSession::where('created_at', '>=', now()->startOfDay())->count(),
                    'escalations_today' => ChatbotMessage::where('role', 'assistant')
                        ->where('was_escalated', true)
                        ->where('created_at', '>=', now()->startOfDay())
                        ->count(),
                    'avg_confidence_7d' => ChatbotMessage::where('role', 'assistant')
                        ->whereNotNull('confidence')
                        ->where('created_at', '>=', now()->subDays(7))
                        ->avg('confidence'),
                ];
            });
        }

        $aiRoutingStats = Cache::remember("dashboard.ai_routing:{$teamId}", 30, function () {
            $since = now()->subDay();

            return [
                'total' => AiRun::where('created_at', '>=', $since)->count(),
                'light' => AiRun::where('created_at', '>=', $since)->where('classified_complexity', 'light')->count(),
                'standard' => AiRun::where('created_at', '>=', $since)->where('classified_complexity', 'standard')->count(),
                'heavy' => AiRun::where('created_at', '>=', $since)->where('classified_complexity', 'heavy')->count(),
                'escalated' => AiRun::where('created_at', '>=', $since)->where('escalation_attempts', '>', 0)->count(),
                'verification_failed' => AiRun::where('created_at', '>=', $since)->where('verification_passed', false)->count(),
            ];
        });

        $ctoKpis = $this->persona === 'cto'
            ? $this->computeCtoKpis($teamId)
            : null;

        return view('livewire.dashboard.dashboard-page', array_merge($kpis, [
            'activeExperiments' => $activeExperiments,
            'alerts' => $alerts,
            'hasProviderKeys' => $hasProviderKeys,
            'chatbotKpis' => $chatbotKpis,
            'chatbotEnabled' => $chatbotEnabled,
            'aiRoutingStats' => $aiRoutingStats,
            'persona' => $this->persona,
            'ctoKpis' => $ctoKpis,
        ]))->layout('layouts.app', ['header' => 'Dashboard']);
    }

    /**
     * CTO-facing KPIs — borrowed from prilog.ai's "audit-ready debt reduction"
     * framing. Single tile that complements (does not replace) the SRE view.
     *
     * @return array<string, int|float>
     */
    private function computeCtoKpis(string $teamId): array
    {
        return Cache::remember("dashboard.cto_kpis:{$teamId}", 60, function () {
            $thirtyDaysAgo = now()->subDays(30);
            $sevenDaysAgo = now()->subDays(7);

            $completedLast30 = Experiment::where('status', ExperimentStatus::Completed)
                ->where('updated_at', '>=', $thirtyDaysAgo)
                ->count();

            $completedPrev30 = Experiment::where('status', ExperimentStatus::Completed)
                ->where('updated_at', '>=', $thirtyDaysAgo->copy()->subDays(30))
                ->where('updated_at', '<', $thirtyDaysAgo)
                ->count();

            $trend = $completedPrev30 > 0
                ? round((($completedLast30 - $completedPrev30) / $completedPrev30) * 100, 1)
                : 0.0;

            $approvalsAutoApproved30d = ApprovalRequest::where('status', ApprovalStatus::Approved)
                ->where('reviewed_at', '>=', $thirtyDaysAgo)
                ->whereJsonContains('context->auto_approved', true)
                ->count();

            return [
                'experimentsCompleted30d' => $completedLast30,
                'experimentsCompletedTrendPct' => $trend,
                'approvalsAutoApproved30d' => $approvalsAutoApproved30d,
                'activeExperiments7d' => Experiment::where('created_at', '>=', $sevenDaysAgo)->count(),
            ];
        });
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
