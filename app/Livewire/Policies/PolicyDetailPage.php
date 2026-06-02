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
