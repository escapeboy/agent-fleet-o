<?php

namespace App\Livewire\Policies;

use App\Domain\Agent\Actions\RollbackAgentPolicyAction;
use App\Domain\Agent\Actions\UpdateAgentPolicyAction;
use App\Domain\Agent\Models\AgentPolicy;
use App\Domain\Agent\Models\AgentPolicyVersion;
use Livewire\Component;

class PolicyDetailPage extends Component
{
    public AgentPolicy $policy;

    // Route-model binding already applies TeamScope (foreign ids 404); assert
    // ownership explicitly so the mutation methods below can't act on another
    // team's policy even if the bound instance were ever set differently.
    public function mount(AgentPolicy $policy): void
    {
        abort_unless($policy->team_id === auth()->user()->current_team_id, 403);
        $this->policy = $policy;
    }

    public function toggleEnabled(): void
    {
        app(UpdateAgentPolicyAction::class)->execute(
            policy: $this->policy,
            enabled: ! $this->policy->enabled,
            createdBy: auth()->id(),
        );
        $this->policy->refresh();
    }

    public function rollback(string $versionId): void
    {
        app(RollbackAgentPolicyAction::class)->execute($this->policy, $versionId, auth()->id());
        $this->policy->refresh();
        session()->flash('status', 'Rolled back — a new version was minted.');
    }

    public function render()
    {
        $versions = AgentPolicyVersion::where('agent_policy_id', $this->policy->id)
            ->orderByDesc('version')
            ->get();

        return view('livewire.policies.policy-detail-page', [
            'versions' => $versions,
            'current' => $this->policy->currentVersion,
        ])->layout('layouts.app', ['header' => 'Policy: '.$this->policy->name]);
    }
}
