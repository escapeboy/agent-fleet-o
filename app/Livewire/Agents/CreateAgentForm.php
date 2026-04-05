<?php

namespace App\Livewire\Agents;

use App\Domain\Agent\Actions\CreateAgentAction;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Skill\Models\Skill;
use App\Domain\Tool\Models\Tool;
use App\Infrastructure\AI\Services\ProviderResolver;
use Livewire\Component;

class CreateAgentForm extends Component
{
    public string $name = '';

    public string $role = '';

    public string $goal = '';

    public string $backstory = '';

    // Personality traits
    public string $personalityTone = '';

    public string $personalityCommunicationStyle = '';

    public string $personalityTraits = '';

    public string $personalityBehavioralRules = '';

    public string $personalityResponseFormat = '';

    public string $provider = 'anthropic';

    public string $model = 'claude-sonnet-4-5';

    public ?int $budgetCapCredits = null;

    public array $selectedSkillIds = [];

    public array $selectedToolIds = [];

    public array $fallbackChain = [];

    public string $executionTier = 'standard';

    public ?int $thinkingBudget = null;

    public bool $useFederation = false;

    public string $federationGroupId = '';

    public bool $useMemory = false;

    public bool $enableScoutPhase = false;

    /** @var array<string> */
    public array $gitRepositoryIds = [];

    public string $toolProfile = '';

    public ?string $knowledgeBaseId = null;

    public bool $evaluationEnabled = false;

    public ?float $evaluationSampleRate = null;

    /** Raw JSON input for the heartbeat definition (optional). */
    public string $heartbeatJson = '';

    public function mount(): void
    {
        $templateSlug = request('template');
        if ($templateSlug) {
            $template = collect(config('agent-templates', []))
                ->firstWhere('slug', $templateSlug);
            if ($template) {
                $this->name = ($template['name'] ?? 'Agent').' (Copy)';
                $this->role = $template['role'] ?? '';
                $this->goal = $template['goal'] ?? '';
                $this->backstory = $template['backstory'] ?? '';
                $personality = $template['personality'] ?? [];
                $this->personalityTone = $personality['tone'] ?? '';
                $this->personalityCommunicationStyle = $personality['communication_style'] ?? '';
                $this->personalityTraits = implode(', ', $personality['traits'] ?? []);
                $this->personalityBehavioralRules = implode("\n", $personality['behavioral_rules'] ?? []);
                $this->personalityResponseFormat = $personality['response_format_preference'] ?? '';
            }
        }
    }

    public function addFallback(): void
    {
        $this->fallbackChain[] = ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'];
    }

    public function removeFallback(int $index): void
    {
        unset($this->fallbackChain[$index]);
        $this->fallbackChain = array_values($this->fallbackChain);
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|min:2|max:255',
            'role' => 'required|max:255',
            'goal' => 'required|max:1000',
            'provider' => 'required|string|max:255',
            'model' => 'required|max:255',
            'thinkingBudget' => 'nullable|integer|min:0|max:100000',
        ];
    }

    public function save(): void
    {
        $this->validate();

        $team = auth()->user()->currentTeam;

        $config = ['execution_tier' => $this->executionTier];

        if ($this->thinkingBudget !== null && $this->thinkingBudget > 0) {
            $config['thinking_budget'] = $this->thinkingBudget;
        }
        $filteredChain = array_filter($this->fallbackChain, fn ($entry) => ! empty($entry['provider']) && ! empty($entry['model']));
        if (! empty($filteredChain)) {
            $config['fallback_chain'] = array_values($filteredChain);
        }

        if ($this->useFederation) {
            $config['use_tool_federation'] = true;
            if ($this->federationGroupId !== '') {
                $config['tool_federation_group_id'] = $this->federationGroupId;
            }
        }

        if ($this->useMemory) {
            $config['use_memory'] = true;
        }

        if ($this->enableScoutPhase) {
            $config['enable_scout_phase'] = true;
        }

        $repoIds = array_values($this->gitRepositoryIds);
        if (! empty($repoIds)) {
            // Filter to only repos belonging to the current team to prevent cross-tenant references
            $validRepoIds = GitRepository::where('team_id', $team->id)
                ->whereIn('id', $repoIds)
                ->pluck('id')
                ->all();
            $config['git_repository_ids'] = $validRepoIds;
        }

        // Build personality array from form fields
        $personality = $this->buildPersonalityArray();

        // Parse optional heartbeat definition JSON
        $heartbeatDefinition = null;
        if (trim($this->heartbeatJson) !== '') {
            $parsed = json_decode($this->heartbeatJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->addError('heartbeatJson', 'Heartbeat definition must be valid JSON.');

                return;
            }
            $heartbeatDefinition = $parsed;
        }

        $agent = app(CreateAgentAction::class)->execute(
            name: $this->name,
            provider: $this->provider,
            model: $this->model,
            teamId: $team->id,
            role: $this->role,
            goal: $this->goal,
            backstory: $this->backstory ?: null,
            budgetCapCredits: $this->budgetCapCredits,
            skillIds: $this->selectedSkillIds,
            toolIds: $this->selectedToolIds,
            config: $config,
            personality: $personality,
        );

        if ($this->toolProfile !== '') {
            $agent->update(['tool_profile' => $this->toolProfile]);
        }

        if ($this->knowledgeBaseId) {
            $agent->update(['knowledge_base_id' => $this->knowledgeBaseId]);
        }

        if ($this->evaluationEnabled) {
            $agent->update([
                'evaluation_enabled' => true,
                'evaluation_sample_rate' => $this->evaluationSampleRate,
            ]);
        }

        if ($heartbeatDefinition !== null) {
            $agent->update(['heartbeat_definition' => $heartbeatDefinition]);
        }

        session()->flash('message', 'Agent created successfully!');

        $this->redirect(route('agents.index'));
    }

    public function toggleSkill(string $skillId): void
    {
        if (in_array($skillId, $this->selectedSkillIds)) {
            $this->selectedSkillIds = array_values(array_diff($this->selectedSkillIds, [$skillId]));
        } else {
            $this->selectedSkillIds[] = $skillId;
        }
    }

    public function toggleTool(string $toolId): void
    {
        if (in_array($toolId, $this->selectedToolIds)) {
            $this->selectedToolIds = array_values(array_diff($this->selectedToolIds, [$toolId]));
        } else {
            $this->selectedToolIds[] = $toolId;
        }
    }

    public function toggleGitRepository(string $repoId): void
    {
        if (in_array($repoId, $this->gitRepositoryIds)) {
            $this->gitRepositoryIds = array_values(array_diff($this->gitRepositoryIds, [$repoId]));
        } else {
            $this->gitRepositoryIds[] = $repoId;
        }
    }

    private function buildPersonalityArray(): ?array
    {
        $personality = array_filter([
            'tone' => $this->personalityTone ?: null,
            'communication_style' => $this->personalityCommunicationStyle ?: null,
            'traits' => $this->personalityTraits
                ? array_map('trim', explode(',', $this->personalityTraits))
                : null,
            'behavioral_rules' => $this->personalityBehavioralRules
                ? array_filter(array_map('trim', explode("\n", $this->personalityBehavioralRules)))
                : null,
            'response_format_preference' => $this->personalityResponseFormat ?: null,
        ]);

        return ! empty($personality) ? $personality : null;
    }

    public function render()
    {
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
        $resolver = app(ProviderResolver::class);
        $team = auth()->user()->currentTeam;
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

        $teamId = auth()->user()->current_team_id;
        $availableGitRepositories = GitRepository::where('team_id', $teamId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $availableKnowledgeBases = KnowledgeBase::where('team_id', $teamId)
            ->orderBy('name')
            ->get();

        return view('livewire.agents.create-agent-form', [
            'availableSkills' => $availableSkills,
            'availableTools' => $availableTools,
            'providers' => $providers,
            'canCreate' => true,
            'availableGitRepositories' => $availableGitRepositories,
            'availableKnowledgeBases' => $availableKnowledgeBases,
        ])->layout('layouts.app', ['header' => 'Create Agent']);
    }
}
