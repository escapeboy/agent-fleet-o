<?php

namespace App\Livewire\Skills;

use App\Domain\Skill\Actions\UpdateSkillAction;
use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use App\Domain\Skill\Models\SkillVersion;
use Livewire\Component;

class SkillDetailPage extends Component
{
    public Skill $skill;
    public string $activeTab = 'overview';

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

        return view('livewire.skills.skill-detail-page', [
            'versions' => $versions,
            'executions' => $executions,
        ])->layout('layouts.app', ['header' => $this->skill->name]);
    }
}
