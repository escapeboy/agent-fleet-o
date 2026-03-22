<?php

namespace App\Livewire\Agents;

use App\Domain\Agent\Actions\CreateAgentFeedbackAction;
use App\Domain\Agent\Actions\RecordAgentConfigRevisionAction;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Enums\FeedbackRating;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentConfigRevision;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Agent\Models\AgentFeedback;
use App\Domain\Agent\Models\AgentRuntimeState;
use App\Domain\Skill\Models\Skill;
use App\Domain\Tool\Models\Tool;
use App\Infrastructure\AI\Services\ProviderResolver;
use Livewire\Component;

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

    public function mount(Agent $agent): void
    {
        $this->agent = $agent;
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

    public function save(): void
    {
        $this->validate([
            'editName' => 'required|min:2|max:255',
            'editRole' => 'required|max:255',
            'editGoal' => 'required|max:1000',
            'editProvider' => 'required|string|max:255',
            'editModel' => 'required|max:255',
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
        ])->layout('layouts.app', ['header' => $this->agent->name]);
    }
}
