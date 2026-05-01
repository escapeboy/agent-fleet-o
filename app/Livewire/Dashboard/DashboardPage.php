<?php

namespace App\Livewire\Dashboard;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Agent\Models\AiRun;
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
use App\Domain\Workflow\Actions\GenerateWorkflowFromPromptAction;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class DashboardPage extends Component
{
    /** @var array<string, bool> */
    public array $widgets = [];

    public string $workflowPrompt = '';

    public bool $workflowGenerating = false;

    public string $workflowError = '';

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
    }

    public function generateWorkflow(): void
    {
        $this->validate(['workflowPrompt' => 'required|string|min:10|max:1000']);

        $this->workflowError = '';
        $this->workflowGenerating = true;

        try {
            $teamId = auth()->user()->current_team_id;
            $result = app(GenerateWorkflowFromPromptAction::class)->execute(
                prompt: $this->workflowPrompt,
                userId: auth()->id(),
                teamId: $teamId,
            );

            if ($result['workflow']) {
                $this->workflowPrompt = '';
                $this->redirect(route('workflows.show', $result['workflow']), navigate: true);
            } else {
                $this->workflowError = implode(' ', $result['errors']) ?: 'Failed to generate workflow. Try a more detailed description.';
            }
        } catch (\Throwable $e) {
            $this->workflowError = 'An error occurred while generating the workflow.';
        } finally {
            $this->workflowGenerating = false;
        }
    }

    public function toggleWidget(string $key): void
    {
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

        return view('livewire.dashboard.dashboard-page', array_merge($kpis, [
            'activeExperiments' => $activeExperiments,
            'alerts' => $alerts,
            'hasProviderKeys' => $hasProviderKeys,
            'chatbotKpis' => $chatbotKpis,
            'chatbotEnabled' => $chatbotEnabled,
            'aiRoutingStats' => $aiRoutingStats,
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
