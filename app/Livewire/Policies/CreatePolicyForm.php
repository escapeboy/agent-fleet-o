<?php

namespace App\Livewire\Policies;

use App\Domain\Agent\Actions\CreateAgentPolicyAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Support\AgentPolicyYamlSerializer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class CreatePolicyForm extends Component
{
    public string $name = '';

    public string $agentId = '';

    public string $riskCeiling = 'medium';

    public bool $autoExecuteEnabled = false;

    public int $autoExecuteThreshold = 18;

    public string $allowedTargetTypes = '';

    public string $deniedTargetTypes = 'migration';

    public string $sensitivePaths = '';

    public ?int $spendCapCredits = null;

    public string $spendCapWindow = 'day';

    public ?int $frequencyCapCount = null;

    public string $frequencyCapWindow = 'day';

    public bool $enabled = false;

    public string $yamlImport = '';

    public function save()
    {
        Gate::authorize('edit-content');

        $this->validate([
            'name' => 'required|string|min:2|max:200',
            // The submitted agent id is attacker-controllable (not just the
            // dropdown), so verify it belongs to the caller's team.
            'agentId' => ['nullable', 'string', Rule::exists('agents', 'id')
                ->where('team_id', auth()->user()->current_team_id)],
            'riskCeiling' => 'required|in:low,medium,high',
            'autoExecuteThreshold' => 'integer|min:0|max:25',
            'spendCapCredits' => 'nullable|integer|min:0',
            'frequencyCapCount' => 'nullable|integer|min:0',
        ]);

        $policy = app(CreateAgentPolicyAction::class)->execute(
            teamId: auth()->user()->current_team_id,
            name: $this->name,
            agentId: $this->agentId !== '' ? $this->agentId : null,
            rules: $this->buildRules(),
            enabled: $this->enabled,
            createdBy: auth()->id(),
        );

        session()->flash('status', 'Policy created.');

        return $this->redirectRoute('policies.show', $policy, navigate: true);
    }

    /**
     * Create a new policy (version 1) from pasted YAML. The name comes from
     * the form field when filled, otherwise from metadata.name. Imported
     * policies always start disabled.
     */
    public function importYaml()
    {
        Gate::authorize('edit-content');

        $parsed = app(AgentPolicyYamlSerializer::class)->parse($this->yamlImport);

        if (trim($this->name) === '' && $parsed['name'] !== null) {
            $this->name = $parsed['name'];
        }

        $this->validate([
            'name' => 'required|string|min:2|max:200',
            'agentId' => ['nullable', 'string', Rule::exists('agents', 'id')
                ->where('team_id', auth()->user()->current_team_id)],
        ]);

        $policy = app(CreateAgentPolicyAction::class)->execute(
            teamId: auth()->user()->current_team_id,
            name: $this->name,
            agentId: $this->agentId !== '' ? $this->agentId : null,
            rules: $parsed['rules'],
            enabled: false,
            createdBy: auth()->id(),
            notes: 'Imported from YAML',
        );

        session()->flash('status', 'Policy imported from YAML (disabled).');

        return $this->redirectRoute('policies.show', $policy, navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRules(): array
    {
        return [
            'allowed_target_types' => $this->csvOrNull($this->allowedTargetTypes),
            'denied_target_types' => $this->csvOrNull($this->deniedTargetTypes) ?? [],
            'risk_ceiling' => $this->riskCeiling,
            'auto_execute' => [
                'enabled' => $this->autoExecuteEnabled,
                'threshold' => $this->autoExecuteThreshold,
            ],
            'spend_cap' => $this->spendCapCredits !== null
                ? ['credits' => $this->spendCapCredits, 'window' => $this->spendCapWindow]
                : null,
            'frequency_cap' => $this->frequencyCapCount !== null
                ? ['count' => $this->frequencyCapCount, 'window' => $this->frequencyCapWindow]
                : null,
            'sensitive_paths' => array_values(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/', $this->sensitivePaths) ?: []),
            )),
        ];
    }

    /**
     * @return list<string>|null
     */
    private function csvOrNull(string $value): ?array
    {
        $items = array_values(array_filter(array_map('trim', explode(',', $value))));

        return $items === [] ? null : $items;
    }

    public function render()
    {
        return view('livewire.policies.create-policy-form', [
            'agents' => Agent::query()
                ->where('team_id', auth()->user()->current_team_id)
                ->orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.app', ['header' => 'New Agent Policy']);
    }
}
