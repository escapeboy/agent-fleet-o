<?php

namespace App\Livewire\Agents;

use App\Domain\Agent\Actions\CreateAgentFeedbackAction;
use App\Domain\Agent\Actions\ExportAgentWorkspaceAction;
use App\Domain\Agent\Actions\RecordAgentConfigRevisionAction;
use App\Domain\Agent\Enums\AgentEnvironment;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Enums\FeedbackRating;
use App\Domain\Agent\Jobs\ExecuteAgentHeartbeatJob;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentConfigRevision;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Agent\Models\AgentFeedback;
use App\Domain\Agent\Models\AgentHook;
use App\Domain\Agent\Models\AgentRuntimeState;
use App\Domain\AgentChatProtocol\Actions\PublishAgentManifestAction;
use App\Domain\AgentChatProtocol\Actions\RevokeAgentManifestAction;
use App\Domain\AgentChatProtocol\Actions\RotateAgentChatSecretAction;
use App\Domain\AgentChatProtocol\Enums\AgentChatVisibility;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Memory\Models\Memory;
use App\Domain\Skill\Models\Skill;
use App\Domain\Tool\Enums\ApprovalTimeoutAction;
use App\Domain\Tool\Enums\ToolApprovalMode;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Models\ToolSearchLog;
use App\Infrastructure\AI\Enums\ReasoningEffort;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentDetailPage extends Component
{
    public Agent $agent;

    public string $activeTab = 'overview';

    // Editing state
    public bool $editing = false;

    public string $editName = '';

    public string $editRole = '';

    public string $editGoal = '';

    public string $editBackstory = '';

    public string $editProvider = '';

    public string $editModel = '';

    public ?int $editBudgetCap = null;

    public array $editFallbackChain = [];

    public string $editExecutionTier = 'standard';

    // Personality editing
    public string $editPersonalityTone = '';

    public string $editPersonalityCommunicationStyle = '';

    public string $editPersonalityTraits = '';

    public string $editPersonalityBehavioralRules = '';

    public string $editPersonalityResponseFormat = '';

    public array $editSkillIds = [];

    public array $editToolIds = [];

    public bool $editUseFederation = false;

    public string $editFederationGroupId = '';

    public bool $editUseMemory = false;

    public bool $editEnableScoutPhase = false;

    public string $editToolProfile = '';

    public string $editEnvironment = '';

    // Output schema editor (Sprint 8 + 12 + 16)
    public string $editOutputSchemaJson = '';

    public ?int $editOutputSchemaMaxRetries = null;

    public string $outputSchemaSaveMessage = '';

    public string $editReasoningEffort = 'none';

    public bool $editUseToolSearch = false;

    public int $editToolSearchTopK = 5;

    /** @var array<string> */
    public array $editKnowledgeBaseIds = [];

    public bool $editEvaluationEnabled = false;

    public ?float $editEvaluationSampleRate = null;

    /** @var array<string> */
    public array $editGitRepositoryIds = [];

    // Hook management
    public bool $showHookForm = false;

    public string $hookName = '';

    public string $hookPosition = 'pre_execute';

    public string $hookType = 'prompt_injection';

    public string $hookConfigJson = '{}';

    public int $hookPriority = 100;

    public ?string $editingHookId = null;

    // System Prompt Template (Identity)
    public bool $useStructuredTemplate = false;

    public ?string $templatePersonality = '';

    /** @var array<string> */
    public array $templateRules = [];

    public ?string $templateContextInjection = '';

    public ?string $templateOutputFormat = '';

    public string $newRule = '';

    // Export
    public string $exportFormat = 'zip';

    public bool $exportIncludeMemories = true;

    public function mount(Agent $agent): void
    {
        $this->agent = $agent;

        $template = $this->agent->system_prompt_template;
        if ($template) {
            $this->useStructuredTemplate = true;
            $this->templatePersonality = $template['personality'] ?? '';
            $this->templateRules = $template['rules'] ?? [];
            $this->templateContextInjection = $template['context_injection'] ?? '';
            $this->templateOutputFormat = $template['output_format'] ?? '';
        }

        // Output schema editor — pretty-print the existing JSONB for the textarea.
        if (! empty($this->agent->output_schema)) {
            $this->editOutputSchemaJson = json_encode($this->agent->output_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        $this->editOutputSchemaMaxRetries = $this->agent->output_schema_max_retries;
    }

    /**
     * Save or clear the agent's output_schema + max-retries. The schema must
     * be valid JSON and decode to an object (or be empty to clear).
     */
    public function saveOutputSchema(): void
    {
        $trimmed = trim($this->editOutputSchemaJson);
        if ($trimmed === '') {
            $this->agent->update([
                'output_schema' => null,
                'output_schema_max_retries' => null,
            ]);
            $this->outputSchemaSaveMessage = 'Schema cleared.';
            $this->agent->refresh();

            return;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->addError('editOutputSchemaJson', 'Invalid JSON: '.json_last_error_msg());

            return;
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            $this->addError('editOutputSchemaJson', 'Schema must be a JSON object, not an array or scalar.');

            return;
        }

        $retries = $this->editOutputSchemaMaxRetries;
        if ($retries !== null && ($retries < 0 || $retries > 5)) {
            $this->addError('editOutputSchemaMaxRetries', 'Max retries must be between 0 and 5.');

            return;
        }

        $this->agent->update([
            'output_schema' => $decoded,
            'output_schema_max_retries' => $retries,
        ]);
        $this->agent->refresh();
        $this->outputSchemaSaveMessage = 'Output schema saved.';
    }

    public function clearOutputSchema(): void
    {
        $this->editOutputSchemaJson = '';
        $this->editOutputSchemaMaxRetries = null;
        $this->saveOutputSchema();
    }

    public function toggleStatus(): void
    {
        $newStatus = $this->agent->status === AgentStatus::Active
            ? AgentStatus::Disabled
            : AgentStatus::Active;

        $this->agent->update(['status' => $newStatus]);
        $this->agent->refresh();
    }

    public function startEdit(): void
    {
        $this->editName = $this->agent->name;
        $this->editRole = $this->agent->role ?? '';
        $this->editGoal = $this->agent->goal ?? '';
        $this->editBackstory = $this->agent->backstory ?? '';
        $this->editProvider = $this->agent->provider;
        $this->editModel = $this->agent->model;
        $this->editBudgetCap = $this->agent->budget_cap_credits;
        $this->editFallbackChain = $this->agent->config['fallback_chain'] ?? [];
        $this->editExecutionTier = $this->agent->config['execution_tier'] ?? 'standard';
        /** @var array<string, mixed> $personality */
        $personality = $this->agent->personality ?? [];
        $this->editPersonalityTone = $personality['tone'] ?? '';
        $this->editPersonalityCommunicationStyle = $personality['communication_style'] ?? '';
        $this->editPersonalityTraits = implode(', ', $personality['traits'] ?? []);
        $this->editPersonalityBehavioralRules = implode("\n", $personality['behavioral_rules'] ?? []);
        $this->editPersonalityResponseFormat = $personality['response_format_preference'] ?? '';
        $this->editSkillIds = $this->agent->skills()->pluck('skills.id')->toArray();
        $this->editToolIds = $this->agent->tools()->pluck('tools.id')->toArray();
        $this->editUseFederation = (bool) ($this->agent->config['use_tool_federation'] ?? false);
        $this->editFederationGroupId = $this->agent->config['tool_federation_group_id'] ?? '';
        $this->editUseMemory = (bool) ($this->agent->config['use_memory'] ?? false);
        $this->editEnableScoutPhase = (bool) ($this->agent->config['enable_scout_phase'] ?? false);
        $this->editGitRepositoryIds = $this->agent->config['git_repository_ids'] ?? [];
        $this->editToolProfile = $this->agent->tool_profile ?? '';
        $this->editEnvironment = $this->agent->environment?->value ?? '';
        $this->editReasoningEffort = $this->agent->config['reasoning_effort'] ?? 'none';
        $this->editUseToolSearch = (bool) ($this->agent->config['use_tool_search'] ?? false);
        $this->editToolSearchTopK = (int) ($this->agent->config['tool_search_top_k'] ?? 5);
        $this->editKnowledgeBaseIds = $this->agent->knowledgeBases()->pluck('knowledge_bases.id')->map(fn ($id) => (string) $id)->toArray();
        $this->editEvaluationEnabled = (bool) $this->agent->evaluation_enabled;
        $this->editEvaluationSampleRate = $this->agent->evaluation_sample_rate;
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->resetValidation();
    }

    public function addFallback(): void
    {
        $this->editFallbackChain[] = ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'];
    }

    public function removeFallback(int $index): void
    {
        unset($this->editFallbackChain[$index]);
        $this->editFallbackChain = array_values($this->editFallbackChain);
    }

    public function toggleSkill(string $skillId): void
    {
        if (in_array($skillId, $this->editSkillIds)) {
            $this->editSkillIds = array_values(array_diff($this->editSkillIds, [$skillId]));
        } else {
            $this->editSkillIds[] = $skillId;
        }
    }

    public function toggleTool(string $toolId): void
    {
        if (in_array($toolId, $this->editToolIds)) {
            $this->editToolIds = array_values(array_diff($this->editToolIds, [$toolId]));
        } else {
            $this->editToolIds[] = $toolId;
        }
    }

    public function toggleGitRepository(string $repoId): void
    {
        if (in_array($repoId, $this->editGitRepositoryIds)) {
            $this->editGitRepositoryIds = array_values(array_diff($this->editGitRepositoryIds, [$repoId]));
        } else {
            $this->editGitRepositoryIds[] = $repoId;
        }
    }

    public function save(): void
    {
        $this->validate([
            'editName' => 'required|min:2|max:255',
            'editRole' => 'required|max:255',
            'editGoal' => 'required|max:1000',
            'editProvider' => 'required|string|max:255',
            'editModel' => 'required|max:255',
            'editEvaluationSampleRate' => 'nullable|numeric|min:0|max:1',
            'editEnvironment' => ['nullable', Rule::enum(AgentEnvironment::class)],
            'editReasoningEffort' => ['nullable', Rule::enum(ReasoningEffort::class)],
            'editUseToolSearch' => ['nullable', 'boolean'],
            'editToolSearchTopK' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $config = $this->agent->config ?? [];
        $filteredChain = array_filter(
            $this->editFallbackChain,
            fn ($entry) => ! empty($entry['provider']) && ! empty($entry['model']),
        );

        if (! empty($filteredChain)) {
            $config['fallback_chain'] = array_values($filteredChain);
        } else {
            unset($config['fallback_chain']);
        }

        $config['execution_tier'] = $this->editExecutionTier;

        if ($this->editUseFederation) {
            $config['use_tool_federation'] = true;
            if ($this->editFederationGroupId !== '') {
                $config['tool_federation_group_id'] = $this->editFederationGroupId;
            } else {
                unset($config['tool_federation_group_id']);
            }
        } else {
            unset($config['use_tool_federation'], $config['tool_federation_group_id']);
        }

        if ($this->editUseMemory) {
            $config['use_memory'] = true;
        } else {
            unset($config['use_memory']);
        }

        if ($this->editEnableScoutPhase) {
            $config['enable_scout_phase'] = true;
        } else {
            unset($config['enable_scout_phase']);
        }

        $repoIds = array_values($this->editGitRepositoryIds);
        if (! empty($repoIds)) {
            // Filter to only repos belonging to the agent's team to prevent cross-tenant references
            $validRepoIds = GitRepository::where('team_id', $this->agent->team_id)
                ->whereIn('id', $repoIds)
                ->pluck('id')
                ->all();
            $config['git_repository_ids'] = $validRepoIds;
        } else {
            unset($config['git_repository_ids']);
        }

        if ($this->editReasoningEffort !== '' && $this->editReasoningEffort !== 'none') {
            $config['reasoning_effort'] = $this->editReasoningEffort;
        } else {
            unset($config['reasoning_effort']);
        }

        if ($this->editUseToolSearch) {
            $config['use_tool_search'] = true;
            $config['tool_search_top_k'] = max(1, min(20, $this->editToolSearchTopK));
        } else {
            unset($config['use_tool_search'], $config['tool_search_top_k']);
        }

        $pricing = config("llm_pricing.providers.{$this->editProvider}.{$this->editModel}");

        // Build personality array
        $personality = array_filter([
            'tone' => $this->editPersonalityTone ?: null,
            'communication_style' => $this->editPersonalityCommunicationStyle ?: null,
            'traits' => $this->editPersonalityTraits
                ? array_map('trim', explode(',', $this->editPersonalityTraits))
                : null,
            'behavioral_rules' => $this->editPersonalityBehavioralRules
                ? array_filter(array_map('trim', explode("\n", $this->editPersonalityBehavioralRules)))
                : null,
            'response_format_preference' => $this->editPersonalityResponseFormat ?: null,
        ]);

        $newConfig = [
            'name' => $this->editName,
            'role' => $this->editRole,
            'goal' => $this->editGoal,
            'backstory' => $this->editBackstory ?: null,
            'personality' => ! empty($personality) ? $personality : null,
            'provider' => $this->editProvider,
            'model' => $this->editModel,
            'budget_cap_credits' => $this->editBudgetCap,
            'tool_profile' => $this->editToolProfile ?: null,
            'environment' => $this->editEnvironment ?: null,
            'evaluation_enabled' => $this->editEvaluationEnabled,
            'evaluation_sample_rate' => $this->editEvaluationEnabled ? ($this->editEvaluationSampleRate ?? 0.2) : 0.0,
            'config' => $config,
            'cost_per_1k_input' => $pricing['input'] ?? 0,
            'cost_per_1k_output' => $pricing['output'] ?? 0,
        ];

        app(RecordAgentConfigRevisionAction::class)->execute(
            agent: $this->agent,
            newData: $newConfig,
            source: 'ui',
            userId: auth()->id(),
        );

        $this->agent->update($newConfig);

        // Sync skills
        $this->agent->skills()->sync($this->editSkillIds);

        // Sync tools
        $toolSyncData = [];
        foreach ($this->editToolIds as $index => $toolId) {
            $toolSyncData[$toolId] = ['priority' => $index];
        }
        $this->agent->tools()->sync($toolSyncData);

        // Sync knowledge bases
        $this->agent->knowledgeBases()->sync($this->editKnowledgeBaseIds);

        $this->agent->refresh();
        $this->editing = false;

        session()->flash('message', 'Agent updated successfully.');
    }

    public function submitFeedback(string $executionId, int $score, ?string $comment = null): void
    {
        $this->authorize('edit-content');

        $execution = AgentExecution::where('agent_id', $this->agent->id)
            ->findOrFail($executionId);

        $rating = FeedbackRating::from(max(-1, min(1, $score)));

        $output = $execution->output ? json_encode($execution->output) : null;
        $input = $execution->input ? json_encode($execution->input) : null;

        app(CreateAgentFeedbackAction::class)->execute(
            agent: $this->agent,
            teamId: $this->agent->team_id,
            rating: $rating,
            comment: $comment,
            outputSnapshot: $output ? mb_substr($output, 0, 2000) : null,
            inputSnapshot: $input ? mb_substr($input, 0, 1000) : null,
            userId: auth()->id(),
            agentExecutionId: $execution->id,
        );

        $this->dispatch('feedback-submitted', executionId: $executionId, score: $score);
        session()->flash('message', 'Feedback recorded.');
    }

    public function deleteAgent(): void
    {
        $this->agent->delete();

        session()->flash('message', 'Agent deleted.');
        $this->redirect(route('agents.index'));
    }

    // ── Agent Chat Protocol tab actions ──────────────────────────────────────

    public function publishChatProtocol(string $visibility): void
    {
        $enum = AgentChatVisibility::tryFrom($visibility);
        if ($enum === null) {
            session()->flash('error', 'Invalid visibility value.');

            return;
        }

        app(PublishAgentManifestAction::class)
            ->execute(agent: $this->agent, visibility: $enum);

        $this->agent->refresh();
        session()->flash('message', 'Chat protocol published for this agent.');
    }

    public function revokeChatProtocol(): void
    {
        app(RevokeAgentManifestAction::class)
            ->execute($this->agent);

        $this->agent->refresh();
        session()->flash('message', 'Chat protocol disabled for this agent.');
    }

    public function rotateChatProtocolSecret(): void
    {
        $secret = app(RotateAgentChatSecretAction::class)
            ->execute($this->agent);

        $this->agent->refresh();
        session()->flash('chat_protocol_new_secret', $secret);
        session()->flash('message', 'New secret generated — copy it now; it is only shown once.');
    }

    /**
     * Toggle the enabled flag of the agent's heartbeat schedule without
     * changing the cron expression or prompt.
     */
    public function toggleHeartbeat(): void
    {
        $current = $this->agent->heartbeat_definition ?? [];
        $current['enabled'] = ! ($current['enabled'] ?? false);
        $this->agent->update(['heartbeat_definition' => $current]);
        $this->agent->refresh();
    }

    /**
     * Immediately dispatch the agent's heartbeat job outside the normal schedule.
     */
    public function runHeartbeatNow(): void
    {
        $definition = $this->agent->heartbeat_definition ?? [];

        if (empty($definition['prompt'])) {
            session()->flash('error', 'No heartbeat prompt configured.');

            return;
        }

        ExecuteAgentHeartbeatJob::dispatch(
            $this->agent->id,
            $this->agent->team_id,
            $definition['prompt'],
        );

        session()->flash('message', 'Heartbeat dispatched.');
    }

    public function saveHook(): void
    {
        $this->validate([
            'hookName' => 'required|string|max:255',
            'hookPosition' => 'required|string',
            'hookType' => 'required|string',
            'hookPriority' => 'required|integer|min:0|max:999',
        ]);

        $config = json_decode($this->hookConfigJson, true) ?? [];

        $data = [
            'team_id' => auth()->user()->current_team_id,
            'agent_id' => $this->agent->id,
            'name' => $this->hookName,
            'position' => $this->hookPosition,
            'type' => $this->hookType,
            'config' => $config,
            'priority' => $this->hookPriority,
            'enabled' => true,
        ];

        if ($this->editingHookId) {
            AgentHook::where('id', $this->editingHookId)->update($data);
        } else {
            AgentHook::create($data);
        }

        $this->resetHookForm();
    }

    public function editHook(string $hookId): void
    {
        $hook = AgentHook::findOrFail($hookId);
        $this->editingHookId = $hook->id;
        $this->hookName = $hook->name;
        $this->hookPosition = $hook->position->value;
        $this->hookType = $hook->type->value;
        $this->hookConfigJson = json_encode($hook->config, JSON_PRETTY_PRINT);
        $this->hookPriority = $hook->priority;
        $this->showHookForm = true;
    }

    public function toggleHook(string $hookId): void
    {
        $hook = AgentHook::findOrFail($hookId);
        $hook->update(['enabled' => ! $hook->enabled]);
    }

    public function deleteHook(string $hookId): void
    {
        AgentHook::where('id', $hookId)->delete();
    }

    public function resetHookForm(): void
    {
        $this->showHookForm = false;
        $this->editingHookId = null;
        $this->hookName = '';
        $this->hookPosition = 'pre_execute';
        $this->hookType = 'prompt_injection';
        $this->hookConfigJson = '{}';
        $this->hookPriority = 100;
    }

    // ── System Prompt Template ──

    public function addRule(): void
    {
        if (trim($this->newRule) !== '') {
            $this->templateRules[] = trim($this->newRule);
            $this->newRule = '';
        }
    }

    public function removeRule(int $index): void
    {
        unset($this->templateRules[$index]);
        $this->templateRules = array_values($this->templateRules);
    }

    public function saveIdentityTemplate(): void
    {
        if (! $this->useStructuredTemplate) {
            $this->agent->update(['system_prompt_template' => null]);
            $this->dispatch('notify', message: 'Switched to classic mode', type: 'success');

            return;
        }

        $this->agent->update([
            'system_prompt_template' => [
                'personality' => $this->templatePersonality,
                'rules' => $this->templateRules,
                'context_injection' => $this->templateContextInjection,
                'output_format' => $this->templateOutputFormat,
            ],
        ]);
        $this->dispatch('notify', message: 'Identity template saved', type: 'success');
    }

    // ── Agent Memories ──

    /** @return Collection<int, Memory> */
    public function getAgentMemoriesProperty(): Collection
    {
        return Memory::withoutGlobalScopes()
            ->where('agent_id', $this->agent->id)
            ->where('team_id', $this->agent->team_id)
            ->latest()
            ->take(50)
            ->get();
    }

    // ── Tool Approval ──

    public function updateToolApproval(string $toolId, string $mode, int $timeout = 30, string $timeoutAction = 'deny'): void
    {
        if (! $this->agent->tools()->where('tools.id', $toolId)->exists()) {
            return;
        }

        $this->agent->tools()->updateExistingPivot($toolId, [
            'approval_mode' => ToolApprovalMode::from($mode),
            'approval_timeout_minutes' => $timeout,
            'approval_timeout_action' => ApprovalTimeoutAction::from($timeoutAction),
        ]);
        $this->dispatch('notify', message: 'Tool approval settings updated', type: 'success');
    }

    // ── Export ──

    public function exportWorkspace(): StreamedResponse
    {
        $action = app(ExportAgentWorkspaceAction::class);
        $path = $action->execute($this->agent, $this->exportFormat, $this->exportIncludeMemories);

        return response()->download($path)->deleteFileAfterSend();
    }

    public function render()
    {
        $skills = $this->agent->skills()->get();
        $tools = $this->agent->tools()->get();

        $executions = AgentExecution::where('agent_id', $this->agent->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $feedbackByExecution = AgentFeedback::where('agent_id', $this->agent->id)
            ->whereIn('agent_execution_id', $executions->pluck('id'))
            ->get()
            ->keyBy('agent_execution_id');

        $resolver = app(ProviderResolver::class);
        $team = auth()->user()->currentTeam;
        $resolvedProvider = $resolver->resolveWithSource(agent: $this->agent, team: $team);
        $providers = $resolver->availableProviders($team);

        // Append team's custom endpoints as selectable providers
        foreach ($resolver->customEndpointsForTeam($team) as $ep) {
            $models = [];
            foreach ($ep->credentials['models'] ?? [] as $m) {
                $models[$m] = ['label' => $m, 'input_cost' => 0, 'output_cost' => 0];
            }
            $providers["custom_endpoint:{$ep->name}"] = [
                'name' => $ep->name.' (Custom)',
                'models' => $models,
            ];
        }

        // Enrich local LLM providers with dynamically discovered models
        foreach ($providers as $key => &$providerData) {
            if (! empty($providerData['http_local'])) {
                $providerData['models'] = $resolver->modelsForProvider($key, $team);
            }
        }
        unset($providerData);

        $availableSkills = Skill::where('status', 'active')->orderBy('name')->get();
        $teamId = auth()->user()->current_team_id;
        $availableTools = Tool::where('status', 'active')
            ->where(function ($q) use ($teamId) {
                $q->where('is_platform', false)
                    ->orWhereHas('activations', function ($q2) use ($teamId) {
                        $q2->where('team_id', $teamId)->where('status', 'active');
                    });
            })
            ->orderBy('name')
            ->get();

        $revisions = AgentConfigRevision::withoutGlobalScopes()
            ->where('agent_id', $this->agent->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $runtimeState = AgentRuntimeState::withoutGlobalScopes()
            ->where('agent_id', $this->agent->id)
            ->first();

        // Compute average LLM steps over last 5 executions for tool loop warning badge.
        $avgSteps = AgentExecution::where('agent_id', $this->agent->id)
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->limit(5)
            ->avg('llm_steps_count') ?? 0;

        $teamId = auth()->user()->current_team_id;
        $availableGitRepositories = GitRepository::where('team_id', $teamId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $availableKnowledgeBases = KnowledgeBase::where('team_id', $teamId)
            ->withCount('chunks')
            ->orderBy('name')
            ->get();

        $hooks = AgentHook::where('agent_id', $this->agent->id)
            ->orWhere(function ($q) {
                $q->whereNull('agent_id')
                    ->where('team_id', auth()->user()->current_team_id);
            })
            ->orderBy('position')
            ->orderBy('priority')
            ->get();

        $recentToolSearches = ($this->agent->config['use_tool_search'] ?? false)
            ? ToolSearchLog::where('agent_id', $this->agent->id)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get()
            : collect();

        return view('livewire.agents.agent-detail-page', [
            'skills' => $skills,
            'tools' => $tools,
            'executions' => $executions,
            'feedbackByExecution' => $feedbackByExecution,
            'providers' => $providers,
            'availableSkills' => $availableSkills,
            'availableTools' => $availableTools,
            'revisions' => $revisions,
            'runtimeState' => $runtimeState,
            'resolvedProvider' => $resolvedProvider,
            'avgSteps' => (float) $avgSteps,
            'availableGitRepositories' => $availableGitRepositories,
            'availableKnowledgeBases' => $availableKnowledgeBases,
            'hooks' => $hooks,
            'recentToolSearches' => $recentToolSearches,
        ])->layout('layouts.app', ['header' => $this->agent->name]);
    }
}
