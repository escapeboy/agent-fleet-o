<?php

namespace App\Livewire\Agents;

use App\Domain\Agent\Actions\CreateAgentAction;
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

        // Build personality array from form fields
        $personality = $this->buildPersonalityArray();

        app(CreateAgentAction::class)->execute(
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

        return view('livewire.agents.create-agent-form', [
            'availableSkills' => $availableSkills,
            'availableTools' => $availableTools,
            'providers' => $providers,
            'canCreate' => true,
        ])->layout('layouts.app', ['header' => 'Create Agent']);
    }
}
