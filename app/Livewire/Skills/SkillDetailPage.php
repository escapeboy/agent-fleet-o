<?php

namespace App\Livewire\Skills;

use App\Domain\Skill\Actions\UpdateSkillAction;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use App\Domain\Skill\Models\SkillVersion;
use App\Infrastructure\AI\Services\ProviderResolver;
use Livewire\Component;

class SkillDetailPage extends Component
{
    public Skill $skill;

    public string $activeTab = 'overview';

    // Editing state
    public bool $editing = false;

    public string $editName = '';

    public string $editDescription = '';

    public string $editType = 'llm';

    public string $editRiskLevel = 'low';

    public string $editSystemPrompt = '';

    public string $editProvider = '';

    public string $editModel = '';

    public int $editMaxTokens = 4096;

    public float $editTemperature = 0.7;

    public string $editPromptTemplate = '';

    public function mount(Skill $skill): void
    {
        $this->skill = $skill;
    }

    public function toggleStatus(): void
    {
        $newStatus = $this->skill->status === SkillStatus::Active
            ? SkillStatus::Disabled
            : SkillStatus::Active;

        app(UpdateSkillAction::class)->execute($this->skill, ['status' => $newStatus]);
        $this->skill->refresh();
    }

    public function startEdit(): void
    {
        $this->editName = $this->skill->name;
        $this->editDescription = $this->skill->description ?? '';
        $this->editType = $this->skill->type->value;
        $this->editRiskLevel = $this->skill->risk_level->value;
        $this->editSystemPrompt = $this->skill->system_prompt ?? '';
        $this->editProvider = $this->skill->configuration['provider'] ?? '';
        $this->editModel = $this->skill->configuration['model'] ?? '';
        $this->editMaxTokens = $this->skill->configuration['max_tokens'] ?? 4096;
        $this->editTemperature = $this->skill->configuration['temperature'] ?? 0.7;
        $this->editPromptTemplate = $this->skill->configuration['prompt_template'] ?? '';
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate([
            'editName' => 'required|min:2|max:255',
            'editDescription' => 'max:1000',
            'editType' => 'required|in:llm,connector,rule,hybrid',
            'editRiskLevel' => 'required|in:low,medium,high,critical',
            'editSystemPrompt' => 'max:10000',
            'editMaxTokens' => 'integer|min:1|max:32768',
            'editTemperature' => 'numeric|min:0|max:2',
        ]);

        $configuration = array_filter([
            'provider' => $this->editProvider ?: null,
            'model' => $this->editModel ?: null,
            'max_tokens' => $this->editMaxTokens,
            'temperature' => $this->editTemperature,
            'prompt_template' => $this->editPromptTemplate ?: null,
        ]);

        app(UpdateSkillAction::class)->execute($this->skill, [
            'name' => $this->editName,
            'description' => $this->editDescription ?: null,
            'type' => SkillType::from($this->editType),
            'risk_level' => RiskLevel::from($this->editRiskLevel),
            'system_prompt' => $this->editSystemPrompt ?: null,
            'configuration' => $configuration,
            'requires_approval' => RiskLevel::from($this->editRiskLevel)->requiresApproval(),
        ], changelog: 'Updated via admin panel');

        $this->skill->refresh();
        $this->editing = false;

        session()->flash('message', 'Skill updated successfully.');
    }

    public function deleteSkill(): void
    {
        $this->skill->delete();

        session()->flash('message', 'Skill deleted.');
        $this->redirect(route('skills.index'));
    }

    public function render()
    {
        $versions = SkillVersion::where('skill_id', $this->skill->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $executions = SkillExecution::where('skill_id', $this->skill->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $providers = app(ProviderResolver::class)->availableProviders();

        return view('livewire.skills.skill-detail-page', [
            'versions' => $versions,
            'executions' => $executions,
            'providers' => $providers,
        ])->layout('layouts.app', ['header' => $this->skill->name]);
    }
}
