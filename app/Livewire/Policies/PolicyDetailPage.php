<?php

namespace App\Livewire\Policies;

use App\Domain\Agent\Actions\RollbackAgentPolicyAction;
use App\Domain\Agent\Actions\UpdateAgentPolicyAction;
use App\Domain\Agent\Models\AgentPolicy;
use App\Domain\Agent\Models\AgentPolicyVersion;
use App\Domain\Agent\Support\AgentPolicyYamlSerializer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PolicyDetailPage extends Component
{
    public AgentPolicy $policy;

    public string $yamlImport = '';

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
        Gate::authorize('edit-content');

        app(UpdateAgentPolicyAction::class)->execute(
            policy: $this->policy,
            enabled: ! $this->policy->enabled,
            createdBy: auth()->id(),
        );
        $this->policy->refresh();
    }

    public function rollback(string $versionId): void
    {
        Gate::authorize('edit-content');

        app(RollbackAgentPolicyAction::class)->execute($this->policy, $versionId, auth()->id());
        $this->policy->refresh();
        session()->flash('status', 'Rolled back — a new version was minted.');
    }

    public function downloadYaml(): StreamedResponse
    {
        abort_unless($this->policy->team_id === auth()->user()->current_team_id, 403);

        $policy = $this->policy;
        $yaml = app(AgentPolicyYamlSerializer::class)->serialize($policy);
        $filename = (Str::slug($policy->name) ?: 'policy').'-v'.($policy->currentVersion->version ?? 1).'.policy.yaml';

        return response()->streamDownload(function () use ($yaml): void {
            echo $yaml;
        }, $filename, ['Content-Type' => 'application/yaml']);
    }

    public function importYaml(): void
    {
        Gate::authorize('edit-content');

        abort_unless($this->policy->team_id === auth()->user()->current_team_id, 403);

        $parsed = app(AgentPolicyYamlSerializer::class)->parse($this->yamlImport);

        app(UpdateAgentPolicyAction::class)->execute(
            policy: $this->policy,
            rules: $parsed['rules'],
            createdBy: auth()->id(),
            notes: 'Imported from YAML',
        );

        $this->yamlImport = '';
        $this->policy->refresh();
        session()->flash('status', 'Imported — a new version was minted.');
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
