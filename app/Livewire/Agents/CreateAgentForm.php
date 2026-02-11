<?php

namespace App\Livewire\Agents;

use App\Domain\Agent\Actions\CreateAgentAction;
use App\Domain\Skill\Models\Skill;
use App\Infrastructure\AI\Services\ProviderResolver;
use Livewire\Component;

class CreateAgentForm extends Component
{
    public string $name = '';
    public string $role = '';
    public string $goal = '';
    public string $backstory = '';
    public string $provider = 'anthropic';
    public string $model = 'claude-sonnet-4-5';
    public ?int $budgetCapCredits = null;
    public array $selectedSkillIds = [];
    public array $fallbackChain = [];

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
        $providerKeys = implode(',', array_keys(app(ProviderResolver::class)->availableProviders()));

        return [
            'name' => 'required|min:2|max:255',
            'role' => 'required|max:255',
            'goal' => 'required|max:1000',
            'provider' => "required|in:{$providerKeys}",
            'model' => 'required|max:255',
        ];
    }

    public function save(): void
    {
        $this->validate();

        $team = auth()->user()->currentTeam;

        $config = [];
        $filteredChain = array_filter($this->fallbackChain, fn ($entry) => ! empty($entry['provider']) && ! empty($entry['model']));
        if (! empty($filteredChain)) {
            $config['fallback_chain'] = array_values($filteredChain);
        }

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
            config: $config,
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

    public function render()
    {
        $availableSkills = Skill::where('status', 'active')->orderBy('name')->get();
        $providers = app(ProviderResolver::class)->availableProviders();

        return view('livewire.agents.create-agent-form', [
            'availableSkills' => $availableSkills,
            'providers' => $providers,
            'canCreate' => true,
        ])->layout('layouts.app', ['header' => 'Create Agent']);
    }
}
