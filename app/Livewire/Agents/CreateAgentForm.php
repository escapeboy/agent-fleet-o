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

        $team = auth()->user()->currentTeam();

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
