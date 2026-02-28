<?php

namespace App\Livewire\Tools;

use App\Domain\Tool\Actions\ActivatePlatformToolAction;
use App\Domain\Tool\Actions\DeactivatePlatformToolAction;
use App\Domain\Tool\Actions\DeleteToolAction;
use App\Domain\Tool\Actions\UpdateToolAction;
use App\Domain\Tool\Enums\ToolRiskLevel;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\TeamToolActivation;
use App\Domain\Tool\Models\Tool;
use Livewire\Component;

class ToolDetailPage extends Component
{
    public Tool $tool;

    public string $activeTab = 'overview';

    // Editing state
    public bool $editing = false;

    public string $editName = '';

    public string $editDescription = '';

    public int $editTimeout = 30;

    public string $editRiskLevel = '';

    // Platform tool activation
    public ?TeamToolActivation $activation = null;

    public array $credentialInputs = [];

    public function mount(Tool $tool): void
    {
        $this->tool = $tool;

        if ($tool->isPlatformTool()) {
            $this->activation = $tool->activationFor(auth()->user()->current_team_id);
            $this->initCredentialInputs();
        }
    }

    protected function initCredentialInputs(): void
    {
        $envVars = $this->tool->transport_config['env'] ?? [];
        $overrides = $this->activation?->credential_overrides ?? [];

        foreach (array_keys($envVars) as $key) {
            $this->credentialInputs[$key] = $overrides[$key] ?? '';
        }
    }

    public function toggleStatus(): void
    {
        $teamId = auth()->user()->current_team_id;

        if ($this->tool->isPlatformTool()) {
            if ($this->activation && $this->activation->isActive()) {
                app(DeactivatePlatformToolAction::class)->execute($this->tool, $teamId);
            } else {
                app(ActivatePlatformToolAction::class)->execute($this->tool, $teamId);
            }
            $this->activation = $this->tool->fresh()->activationFor($teamId);

            return;
        }

        $newStatus = $this->tool->status === ToolStatus::Active
            ? ToolStatus::Disabled
            : ToolStatus::Active;

        app(UpdateToolAction::class)->execute($this->tool, status: $newStatus);
        $this->tool->refresh();
    }

    public function saveCredentials(): void
    {
        $teamId = auth()->user()->current_team_id;
        $overrides = array_filter($this->credentialInputs, fn ($v) => $v !== '');

        TeamToolActivation::updateOrCreate(
            ['team_id' => $teamId, 'tool_id' => $this->tool->id],
            [
                'status'               => 'active',
                'credential_overrides' => $overrides,
                'activated_at'         => now(),
            ],
        );

        $this->activation = $this->tool->fresh()->activationFor($teamId);
        session()->flash('message', 'Credentials saved and tool activated.');
    }

    public function startEdit(): void
    {
        if ($this->tool->isPlatformTool()) {
            return;
        }

        $this->editName        = $this->tool->name;
        $this->editDescription = $this->tool->description ?? '';
        $this->editTimeout     = $this->tool->settings['timeout'] ?? 30;
        $this->editRiskLevel   = $this->tool->risk_level?->value ?? '';
        $this->editing         = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->resetValidation();
    }

    public function save(): void
    {
        if ($this->tool->isPlatformTool()) {
            return;
        }

        $this->validate([
            'editName'        => 'required|min:2|max:255',
            'editDescription' => 'max:1000',
            'editTimeout'     => 'integer|min:1|max:300',
        ]);

        app(UpdateToolAction::class)->execute(
            $this->tool,
            name: $this->editName,
            description: $this->editDescription ?: null,
            settings: ['timeout' => $this->editTimeout],
            riskLevel: $this->editRiskLevel ? ToolRiskLevel::from($this->editRiskLevel) : null,
        );

        $this->tool->refresh();
        $this->editing = false;

        session()->flash('message', 'Tool updated successfully.');
    }

    public function deleteTool(): void
    {
        if ($this->tool->isPlatformTool()) {
            return;
        }

        app(DeleteToolAction::class)->execute($this->tool);

        session()->flash('message', 'Tool deleted.');
        $this->redirect(route('tools.index'));
    }

    public function render()
    {
        $agents = $this->tool->agents()->get();

        return view('livewire.tools.tool-detail-page', [
            'agents'     => $agents,
            'riskLevels' => ToolRiskLevel::cases(),
        ])->layout('layouts.app', ['header' => $this->tool->name]);
    }
}
