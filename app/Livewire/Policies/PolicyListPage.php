<?php

namespace App\Livewire\Policies;

use App\Domain\Agent\Actions\UpdateAgentPolicyAction;
use App\Domain\Agent\Models\AgentPolicy;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class PolicyListPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function toggleEnabled(string $policyId): void
    {
        // TeamScope already restricts to the current team; scope explicitly too
        // (defense-in-depth on a mutation path).
        $policy = AgentPolicy::where('team_id', auth()->user()->current_team_id)->find($policyId);
        if ($policy) {
            app(UpdateAgentPolicyAction::class)->execute(
                policy: $policy,
                enabled: ! $policy->enabled,
                createdBy: auth()->id(),
            );
        }
    }

    public function render()
    {
        $query = AgentPolicy::query()
            ->where('team_id', auth()->user()->current_team_id)
            ->with(['currentVersion', 'agent']);

        if ($this->search) {
            $query->where('name', 'ilike', "%{$this->search}%");
        }

        return view('livewire.policies.policy-list-page', [
            'policies' => $query->latest('updated_at')->paginate(20),
            'globalEnabled' => (bool) config('agent_policies.enabled', false),
        ])->layout('layouts.app', ['header' => 'Agent Policies']);
    }
}
