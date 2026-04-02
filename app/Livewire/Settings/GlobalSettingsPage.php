<?php

namespace App\Livewire\Settings;

use App\Domain\Agent\Actions\DisableAgentAction;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Outbound\Services\OutboundCredentialResolver;
use App\Domain\Shared\Services\DeploymentMode;
use App\Domain\System\Services\VersionCheckService;
use App\Domain\Tool\Actions\ImportMcpServersAction;
use App\Domain\Tool\Services\McpConfigDiscovery;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Models\Blacklist;
use App\Models\GlobalSetting;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class GlobalSettingsPage extends Component
{
    use WithFileUploads;

    // Active tab
    public string $activeTab = 'general';

    // Budget settings
    public int $globalBudgetCap = 100000;

    public int $defaultExperimentBudgetCap = 10000;

    public int $budgetAlertThresholdPct = 80;

    // Rate limit settings
    public int $emailRateLimit = 10;

    public int $telegramRateLimit = 20;

    public int $slackRateLimit = 20;

    public int $webhookRateLimit = 50;

    public int $discordRateLimit = 20;

    public int $teamsRateLimit = 20;

    public int $googleChatRateLimit = 20;

    public int $whatsappRateLimit = 10;

    public int $targetCooldownDays = 7;

    // Media analysis
    public bool $mediaAnalysisEnabled = false;

    // Default pipeline LLM
    public string $defaultLlmProvider = 'anthropic';

    public string $defaultLlmModel = 'claude-sonnet-4-5';

    // Assistant LLM
    public string $assistantProvider = 'anthropic';

    public string $assistantModel = 'claude-sonnet-4-5';

    // Approval settings
    public int $approvalTimeoutHours = 48;

    // Experiment defaults
    public int $defaultExperimentTtl = 120;

    // Skill degradation thresholds
    public float $skillReliabilityThreshold = 0.6;

    public float $skillQualityThreshold = 0.5;

    public int $skillMinSampleSize = 10;

    // Blacklist form
    public string $blacklistType = 'email';

    public string $blacklistValue = '';

    public string $blacklistReason = '';

    // Update check settings
    public bool $updateCheckEnabled = true;

    // AI Routing
    public bool $budgetPressureEnabled = true;

    public int $budgetPressureLow = 50;

    public int $budgetPressureMedium = 75;

    public int $budgetPressureHigh = 90;

    public bool $escalationEnabled = true;

    public int $escalationMaxAttempts = 2;

    public bool $verificationEnabled = true;

    public int $verificationMaxRetries = 2;

    public bool $stuckDetectionEnabled = true;

    // MCP Import
    public string $mcpJsonInput = '';

    public $mcpUploadFile;

    public array $discoveredServers = [];

    public array $selectedServers = [];

    public ?array $mcpImportResult = null;

    public function mount(): void
    {
        // In cloud mode only super-admins may access platform-wide settings.
        // In self-hosted mode the owner of the sole team is effectively the admin.
        if (app(DeploymentMode::class)->isCloud()) {
            abort_unless(auth()->user()?->is_super_admin, 403);
        }

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
        $this->discordRateLimit = $rateLimits['discord'] ?? 20;
        $this->teamsRateLimit = $rateLimits['teams'] ?? 20;
        $this->googleChatRateLimit = $rateLimits['google_chat'] ?? 20;
        $this->whatsappRateLimit = $rateLimits['whatsapp'] ?? 10;

        $this->targetCooldownDays = GlobalSetting::get('target_cooldown_days', 7);

        $this->mediaAnalysisEnabled = (bool) GlobalSetting::get('media_analysis_enabled', false);
        $this->updateCheckEnabled = (bool) GlobalSetting::get('update_check_enabled', true);
        $this->approvalTimeoutHours = GlobalSetting::get('approval_timeout_hours', 48);

        $this->defaultExperimentTtl = (int) GlobalSetting::get('default_experiment_ttl', config('experiments.default_ttl_minutes', 120));
        $this->skillReliabilityThreshold = (float) GlobalSetting::get('skill_reliability_threshold', config('skills.degradation.reliability_threshold', 0.6));
        $this->skillQualityThreshold = (float) GlobalSetting::get('skill_quality_threshold', config('skills.degradation.quality_threshold', 0.5));
        $this->skillMinSampleSize = (int) GlobalSetting::get('skill_min_sample_size', config('skills.degradation.min_sample_size', 10));

        $this->defaultLlmProvider = GlobalSetting::get('default_llm_provider', 'anthropic') ?? 'anthropic';
        $this->defaultLlmModel = GlobalSetting::get('default_llm_model', 'claude-sonnet-4-5') ?? 'claude-sonnet-4-5';

        $this->assistantProvider = GlobalSetting::get('assistant_llm_provider', 'anthropic') ?? 'anthropic';
        $this->assistantModel = GlobalSetting::get('assistant_llm_model', 'claude-sonnet-4-5') ?? 'claude-sonnet-4-5';

        // AI Routing
        $this->budgetPressureEnabled = (bool) GlobalSetting::get('ai_routing.budget_pressure_enabled', config('ai_routing.budget_pressure.enabled', true));
        $this->budgetPressureLow = (int) GlobalSetting::get('ai_routing.budget_pressure_low', config('ai_routing.budget_pressure.thresholds.low', 50));
        $this->budgetPressureMedium = (int) GlobalSetting::get('ai_routing.budget_pressure_medium', config('ai_routing.budget_pressure.thresholds.medium', 75));
        $this->budgetPressureHigh = (int) GlobalSetting::get('ai_routing.budget_pressure_high', config('ai_routing.budget_pressure.thresholds.high', 90));
        $this->escalationEnabled = (bool) GlobalSetting::get('ai_routing.escalation_enabled', config('ai_routing.escalation.enabled', true));
        $this->escalationMaxAttempts = (int) GlobalSetting::get('ai_routing.escalation_max_attempts', config('ai_routing.escalation.max_attempts', 2));
        $this->verificationEnabled = (bool) GlobalSetting::get('ai_routing.verification_enabled', config('ai_routing.verification.enabled', true));
        $this->verificationMaxRetries = (int) GlobalSetting::get('ai_routing.verification_max_retries', config('ai_routing.verification.max_retries', 2));
        $this->stuckDetectionEnabled = (bool) GlobalSetting::get('ai_routing.stuck_detection_enabled', config('ai_routing.stuck_detection.enabled', true));
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
            'discordRateLimit' => 'required|integer|min:1',
            'teamsRateLimit' => 'required|integer|min:1',
            'googleChatRateLimit' => 'required|integer|min:1',
            'whatsappRateLimit' => 'required|integer|min:1',
            'targetCooldownDays' => 'required|integer|min:0',
        ]);

        GlobalSetting::set('channel_rate_limits', [
            'email' => $this->emailRateLimit,
            'telegram' => $this->telegramRateLimit,
            'slack' => $this->slackRateLimit,
            'webhook' => $this->webhookRateLimit,
            'discord' => $this->discordRateLimit,
            'teams' => $this->teamsRateLimit,
            'google_chat' => $this->googleChatRateLimit,
            'whatsapp' => $this->whatsappRateLimit,
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

    public function savePlatformAiDefaults(): void
    {
        $this->validate([
            'defaultExperimentTtl' => 'required|integer|min:5|max:1440',
            'skillReliabilityThreshold' => 'required|numeric|min:0|max:1',
            'skillQualityThreshold' => 'required|numeric|min:0|max:1',
            'skillMinSampleSize' => 'required|integer|min:1|max:1000',
        ]);

        GlobalSetting::set('default_experiment_ttl', $this->defaultExperimentTtl);
        GlobalSetting::set('skill_reliability_threshold', $this->skillReliabilityThreshold);
        GlobalSetting::set('skill_quality_threshold', $this->skillQualityThreshold);
        GlobalSetting::set('skill_min_sample_size', $this->skillMinSampleSize);

        session()->flash('message', 'Platform AI defaults saved.');
    }

    public function saveMediaAnalysisSettings(): void
    {
        GlobalSetting::set('media_analysis_enabled', $this->mediaAnalysisEnabled);

        session()->flash('message', 'Media analysis settings saved.');
    }

    public function saveUpdateSettings(): void
    {
        GlobalSetting::set('update_check_enabled', $this->updateCheckEnabled);

        if (! $this->updateCheckEnabled) {
            // Clear cached version info when disabling checks
            cache()->forget('system.latest_version');
            cache()->forget('system.latest_version_full');
        }

        session()->flash('message', 'Update settings saved.');
    }

    public function forceUpdateCheck(): void
    {
        $service = app(VersionCheckService::class);

        if (! $service->isCheckEnabled()) {
            session()->flash('mcp-error', 'Update checks are disabled. Enable them first.');

            return;
        }

        $latest = $service->forceRefresh();

        if ($latest === null) {
            session()->flash('mcp-error', 'Could not reach GitHub. Check network connectivity.');

            return;
        }

        if ($service->isUpdateAvailable()) {
            session()->flash('message', "Update available: v{$latest}");
        } else {
            session()->flash('message', 'You are running the latest version ('.$service->getInstalledVersion().').');
        }
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

    public function saveAiRoutingSettings(): void
    {
        $this->validate([
            'budgetPressureLow' => 'required|integer|min:0|max:100',
            'budgetPressureMedium' => 'required|integer|min:0|max:100',
            'budgetPressureHigh' => 'required|integer|min:0|max:100',
            'escalationMaxAttempts' => 'required|integer|min:1|max:5',
            'verificationMaxRetries' => 'required|integer|min:1|max:5',
        ]);

        GlobalSetting::set('ai_routing.budget_pressure_enabled', $this->budgetPressureEnabled);
        GlobalSetting::set('ai_routing.budget_pressure_low', $this->budgetPressureLow);
        GlobalSetting::set('ai_routing.budget_pressure_medium', $this->budgetPressureMedium);
        GlobalSetting::set('ai_routing.budget_pressure_high', $this->budgetPressureHigh);
        GlobalSetting::set('ai_routing.escalation_enabled', $this->escalationEnabled);
        GlobalSetting::set('ai_routing.escalation_max_attempts', $this->escalationMaxAttempts);
        GlobalSetting::set('ai_routing.verification_enabled', $this->verificationEnabled);
        GlobalSetting::set('ai_routing.verification_max_retries', $this->verificationMaxRetries);
        GlobalSetting::set('ai_routing.stuck_detection_enabled', $this->stuckDetectionEnabled);

        session()->flash('message', 'AI routing settings saved.');
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

    public function saveAssistantLlm(): void
    {
        $this->validate([
            'assistantProvider' => 'required|string',
            'assistantModel' => 'required|string',
        ]);

        GlobalSetting::set('assistant_llm_provider', $this->assistantProvider);
        GlobalSetting::set('assistant_llm_model', $this->assistantModel);

        session()->flash('message', 'Assistant LLM saved.');
    }

    #[On('connector-saved')]
    public function refreshConnectors(): void
    {
        // Re-render triggers fresh connector data from render()
    }

    public function rescanLocalAgents(): void
    {
        Gate::authorize('feature.local_agents');

        $discovery = app(LocalAgentDiscovery::class);

        if ($discovery->isBridgeMode() && ! $discovery->bridgeHealth()) {
            session()->flash('error', 'Bridge daemon is not reachable. Start the bridge on your host machine and try again.');

            return;
        }

        $detected = $discovery->detect();
        $count = count($detected);

        session()->flash('message', "Local agent scan complete. Found {$count} agent(s).");
    }

    public function parseMcpJson(): void
    {
        $this->validate([
            'mcpJsonInput' => 'required|string|max:1048576',
        ]);

        $discovery = app(McpConfigDiscovery::class);
        $this->discoveredServers = $discovery->parseJsonInput($this->mcpJsonInput);
        $this->selectedServers = array_keys($this->discoveredServers);
        $this->mcpImportResult = null;

        if (empty($this->discoveredServers)) {
            session()->flash('mcp-error', 'No MCP servers found in the provided JSON. Ensure it contains a "mcpServers" or "servers" key.');
        }
    }

    public function parseMcpUpload(): void
    {
        $this->validate([
            'mcpUploadFile' => 'required|file|max:1024|mimes:json,txt',
        ]);

        $content = $this->mcpUploadFile->get();
        $discovery = app(McpConfigDiscovery::class);
        $this->discoveredServers = $discovery->parseJsonInput($content);
        $this->selectedServers = array_keys($this->discoveredServers);
        $this->mcpImportResult = null;

        if (empty($this->discoveredServers)) {
            session()->flash('mcp-error', 'No MCP servers found in the uploaded file.');
        }
    }

    public function scanHostMcpServers(): void
    {
        Gate::authorize('feature.mcp_host_scan');

        $discovery = app(McpConfigDiscovery::class);
        $result = $discovery->scanAllSources();
        $this->discoveredServers = $result['servers'];
        $this->selectedServers = array_keys($this->discoveredServers);
        $this->mcpImportResult = null;

        if (empty($this->discoveredServers)) {
            session()->flash('mcp-error', 'No MCP servers found. Configure them in your IDE first.');
        }
    }

    public function importSelectedServers(): void
    {
        if (empty($this->selectedServers)) {
            session()->flash('mcp-error', 'No servers selected for import.');

            return;
        }

        $serversToImport = [];
        foreach ($this->selectedServers as $index) {
            if (isset($this->discoveredServers[$index])) {
                $serversToImport[] = $this->discoveredServers[$index];
            }
        }

        /** @var User $user */
        $user = auth()->user();
        $teamId = $user->current_team_id;

        $importer = app(ImportMcpServersAction::class);
        $result = $importer->execute($teamId, $serversToImport);

        $this->mcpImportResult = [
            'imported' => $result->imported,
            'skipped' => $result->skipped,
            'failed' => $result->failed,
            'details' => $result->details,
            'has_credentials' => $result->hasCredentialPlaceholders(),
            'credential_count' => $result->credentialCount(),
        ];

        // Clear discovered servers after import
        $this->discoveredServers = [];
        $this->selectedServers = [];
        $this->mcpJsonInput = '';

        session()->flash('message', "Imported {$result->imported} MCP server(s), skipped {$result->skipped}.");
    }

    public function clearMcpDiscovery(): void
    {
        $this->discoveredServers = [];
        $this->selectedServers = [];
        $this->mcpImportResult = null;
        $this->mcpJsonInput = '';
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
        $mode = app(DeploymentMode::class);

        // Local agent data is only meaningful in self-hosted mode
        $localAgentsEnabled = false;
        $detectedLocalAgents = [];
        $allLocalAgents = [];
        $bridgeMode = false;
        $bridgeConnected = false;

        $bridgeSecretMissing = false;
        $relayMode = false;

        if ($mode->isSelfHosted()) {
            $discovery = app(LocalAgentDiscovery::class);
            $relayMode = $discovery->isRelayMode();
            $bridgeMode = $relayMode || $discovery->isBridgeMode();
            $localAgentsEnabled = config('local_agents.enabled');
            $detectedLocalAgents = $discovery->detect();
            $allLocalAgents = $discovery->allAgents();
            $bridgeConnected = $bridgeMode ? $discovery->bridgeHealth() : false;
            $bridgeSecretMissing = $discovery->needsBridgeConfig();
        }

        $bridgeConnection = ($relayMode && $bridgeConnected)
            ? BridgeConnection::active()->latest('connected_at')->first()
            : null;

        $resolver = app(OutboundCredentialResolver::class);
        $channels = [
            'telegram' => ['label' => 'Telegram',        'icon' => 'paper-airplane'],
            'slack' => ['label' => 'Slack',           'icon' => 'chat-bubble-left-right'],
            'discord' => ['label' => 'Discord',         'icon' => 'chat-bubble-oval-left'],
            'teams' => ['label' => 'Microsoft Teams', 'icon' => 'building-office'],
            'google_chat' => ['label' => 'Google Chat',     'icon' => 'chat-bubble-left'],
            'whatsapp' => ['label' => 'WhatsApp',        'icon' => 'phone'],
            'email' => ['label' => 'Email (SMTP)',    'icon' => 'envelope'],
            'webhook' => ['label' => 'Webhook',         'icon' => 'globe-alt'],
        ];

        $connectorStatuses = [];
        foreach ($channels as $key => $meta) {
            $source = $resolver->getSource($key);
            $dbConfig = $resolver->getDbConfig($key);
            $connectorStatuses[$key] = [
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'configured' => $resolver->isConfigured($key),
                'source' => $source,
                'lastTestedAt' => $dbConfig?->last_tested_at,
                'lastTestStatus' => $dbConfig?->last_test_status,
            ];
        }

        $mode = app(DeploymentMode::class);
        $versionService = $mode->isSelfHosted() ? app(VersionCheckService::class) : null;

        return view('livewire.settings.global-settings-page', [
            'blacklistEntries' => Blacklist::orderByDesc('created_at')->get(),
            'installedVersion' => $versionService?->getInstalledVersion(),
            'latestVersion' => $versionService?->getLatestVersion(),
            'updateAvailable' => $versionService?->isUpdateAvailable() ?? false,
            'updateInfo' => $versionService?->getUpdateInfo(),
            'agents' => Agent::with('circuitBreakerState')->orderBy('name')->get(),
            'localAgentsEnabled' => $localAgentsEnabled,
            'detectedLocalAgents' => $detectedLocalAgents,
            'allLocalAgents' => $allLocalAgents,
            'bridgeMode' => $bridgeMode,
            'bridgeConnected' => $bridgeConnected,
            'bridgeSecretMissing' => $bridgeSecretMissing,
            'relayMode' => $relayMode,
            'bridgeConnection' => $bridgeConnection,
            'providers' => app(ProviderResolver::class)->availableProviders(auth()->user()?->currentTeam),
            'connectorStatuses' => $connectorStatuses,
        ])->layout('layouts.app', ['header' => 'Settings']);
    }
}
