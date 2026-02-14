<?php

namespace App\Livewire\Settings;

use App\Domain\Agent\Actions\DisableAgentAction;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use App\Models\Blacklist;
use App\Models\GlobalSetting;
use Livewire\Component;

class GlobalSettingsPage extends Component
{
    // Budget settings
    public int $globalBudgetCap = 100000;

    public int $defaultExperimentBudgetCap = 10000;

    public int $budgetAlertThresholdPct = 80;

    // Rate limit settings
    public int $emailRateLimit = 10;

    public int $telegramRateLimit = 20;

    public int $slackRateLimit = 20;

    public int $webhookRateLimit = 50;

    public int $targetCooldownDays = 7;

    // Default pipeline LLM
    public string $defaultLlmProvider = 'anthropic';

    public string $defaultLlmModel = 'claude-sonnet-4-5';

    // Approval settings
    public int $approvalTimeoutHours = 48;

    // Blacklist form
    public string $blacklistType = 'email';

    public string $blacklistValue = '';

    public string $blacklistReason = '';

    public function mount(): void
    {
        $this->globalBudgetCap = GlobalSetting::get('global_budget_cap', 100000);
        $this->defaultExperimentBudgetCap = GlobalSetting::get('default_experiment_budget_cap', 10000);
        $this->budgetAlertThresholdPct = GlobalSetting::get('budget_alert_threshold_pct', 80);

        $rateLimits = GlobalSetting::get('channel_rate_limits', [
            'email' => 10, 'telegram' => 20, 'slack' => 20, 'webhook' => 50,
        ]);
        $this->emailRateLimit = $rateLimits['email'] ?? 10;
        $this->telegramRateLimit = $rateLimits['telegram'] ?? 20;
        $this->slackRateLimit = $rateLimits['slack'] ?? 20;
        $this->webhookRateLimit = $rateLimits['webhook'] ?? 50;

        $this->targetCooldownDays = GlobalSetting::get('target_cooldown_days', 7);
        $this->approvalTimeoutHours = GlobalSetting::get('approval_timeout_hours', 48);

        $this->defaultLlmProvider = GlobalSetting::get('default_llm_provider', 'anthropic') ?? 'anthropic';
        $this->defaultLlmModel = GlobalSetting::get('default_llm_model', 'claude-sonnet-4-5') ?? 'claude-sonnet-4-5';
    }

    public function saveBudgetSettings(): void
    {
        $this->validate([
            'globalBudgetCap' => 'required|integer|min:0',
            'defaultExperimentBudgetCap' => 'required|integer|min:0',
            'budgetAlertThresholdPct' => 'required|integer|min:0|max:100',
        ]);

        GlobalSetting::set('global_budget_cap', $this->globalBudgetCap);
        GlobalSetting::set('default_experiment_budget_cap', $this->defaultExperimentBudgetCap);
        GlobalSetting::set('budget_alert_threshold_pct', $this->budgetAlertThresholdPct);

        session()->flash('message', 'Budget settings saved.');
    }

    public function saveRateLimitSettings(): void
    {
        $this->validate([
            'emailRateLimit' => 'required|integer|min:1',
            'telegramRateLimit' => 'required|integer|min:1',
            'slackRateLimit' => 'required|integer|min:1',
            'webhookRateLimit' => 'required|integer|min:1',
            'targetCooldownDays' => 'required|integer|min:0',
        ]);

        GlobalSetting::set('channel_rate_limits', [
            'email' => $this->emailRateLimit,
            'telegram' => $this->telegramRateLimit,
            'slack' => $this->slackRateLimit,
            'webhook' => $this->webhookRateLimit,
        ]);
        GlobalSetting::set('target_cooldown_days', $this->targetCooldownDays);

        session()->flash('message', 'Rate limit settings saved.');
    }

    public function saveApprovalSettings(): void
    {
        $this->validate([
            'approvalTimeoutHours' => 'required|integer|min:1',
        ]);

        GlobalSetting::set('approval_timeout_hours', $this->approvalTimeoutHours);

        session()->flash('message', 'Approval settings saved.');
    }

    public function addBlacklistEntry(): void
    {
        $this->validate([
            'blacklistType' => 'required|in:email,domain,company,keyword',
            'blacklistValue' => 'required|string|max:255',
        ]);

        Blacklist::create([
            'type' => $this->blacklistType,
            'value' => $this->blacklistValue,
            'reason' => $this->blacklistReason ?: null,
            'added_by' => auth()->id(),
        ]);

        $this->blacklistValue = '';
        $this->blacklistReason = '';

        session()->flash('message', 'Blacklist entry added.');
    }

    public function removeBlacklistEntry(string $id): void
    {
        Blacklist::where('id', $id)->delete();

        session()->flash('message', 'Blacklist entry removed.');
    }

    public function saveDefaultLlm(): void
    {
        $this->validate([
            'defaultLlmProvider' => 'required|string',
            'defaultLlmModel' => 'required|string',
        ]);

        GlobalSetting::set('default_llm_provider', $this->defaultLlmProvider);
        GlobalSetting::set('default_llm_model', $this->defaultLlmModel);

        session()->flash('message', 'Default pipeline LLM saved.');
    }

    public function rescanLocalAgents(): void
    {
        $discovery = app(LocalAgentDiscovery::class);
        $detected = $discovery->detect();
        $count = count($detected);

        session()->flash('message', "Local agent scan complete. Found {$count} agent(s).");
    }

    public function toggleAgent(string $agentId): void
    {
        $agent = Agent::findOrFail($agentId);

        if ($agent->status === AgentStatus::Disabled) {
            $agent->update(['status' => AgentStatus::Active]);
            session()->flash('message', "Agent '{$agent->name}' enabled.");
        } else {
            app(DisableAgentAction::class)->execute($agent, 'Disabled from admin settings');
            session()->flash('message', "Agent '{$agent->name}' disabled.");
        }
    }

    public function render()
    {
        $discovery = app(LocalAgentDiscovery::class);
        $bridgeMode = $discovery->isBridgeMode();

        return view('livewire.settings.global-settings-page', [
            'blacklistEntries' => Blacklist::orderByDesc('created_at')->get(),
            'agents' => Agent::with('circuitBreakerState')->orderBy('name')->get(),
            'localAgentsEnabled' => config('local_agents.enabled'),
            'detectedLocalAgents' => $discovery->detect(),
            'allLocalAgents' => $discovery->allAgents(),
            'bridgeMode' => $bridgeMode,
            'bridgeConnected' => $bridgeMode ? $discovery->bridgeHealth() : false,
            'providers' => config('llm_providers', []),
        ])->layout('layouts.app', ['header' => 'Settings']);
    }
}
