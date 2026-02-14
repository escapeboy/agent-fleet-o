<?php

namespace App\Livewire\Agents;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
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
        $providerKeys = implode(',', array_keys(app(ProviderResolver::class)->availableProviders()));

        $this->validate([
            'editName' => 'required|min:2|max:255',
            'editRole' => 'required|max:255',
            'editGoal' => 'required|max:1000',
            'editProvider' => "required|in:{$providerKeys}",
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

        $pricing = config("llm_pricing.providers.{$this->editProvider}.{$this->editModel}");

        $this->agent->update([
            'name' => $this->editName,
            'role' => $this->editRole,
            'goal' => $this->editGoal,
            'backstory' => $this->editBackstory ?: null,
            'provider' => $this->editProvider,
            'model' => $this->editModel,
            'budget_cap_credits' => $this->editBudgetCap,
            'config' => $config,
            'cost_per_1k_input' => $pricing['input'] ?? 0,
            'cost_per_1k_output' => $pricing['output'] ?? 0,
        ]);

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

        $providers = app(ProviderResolver::class)->availableProviders();
        $availableSkills = Skill::where('status', 'active')->orderBy('name')->get();
        $availableTools = Tool::where('status', 'active')->orderBy('name')->get();

        return view('livewire.agents.agent-detail-page', [
            'skills' => $skills,
            'tools' => $tools,
            'executions' => $executions,
            'providers' => $providers,
            'availableSkills' => $availableSkills,
            'availableTools' => $availableTools,
        ])->layout('layouts.app', ['header' => $this->agent->name]);
    }
}
