<?php

namespace App\Livewire\Skills;

use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillVersion;
use Illuminate\View\View;
use Livewire\Component;

class SkillLineagePanel extends Component
{
    public string $skillId;

    public bool $showPanel = false;

    public function mount(string $skillId): void
    {
        $this->skillId = $skillId;
    }

    public function togglePanel(): void
    {
        $this->showPanel = ! $this->showPanel;
    }

    /**
     * Build node and edge arrays describing the version lineage DAG.
     *
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, string>>}
     */
    public function getLineageData(): array
    {
        $skill = Skill::withoutGlobalScopes()->find($this->skillId);

        if (! $skill) {
            return ['nodes' => [], 'edges' => []];
        }

        $versions = SkillVersion::query()
            ->where('skill_id', $this->skillId)
            ->orderBy('created_at')
            ->get();

        $nodes = $versions->map(fn (SkillVersion $v) => [
            'id' => $v->id,
            'version' => $v->version,
            'evolution_type' => $v->evolution_type ?? 'manual',
            'changelog' => $v->changelog ?? '',
            'created_at' => $v->created_at?->diffForHumans() ?? '',
            'parent_version_id' => $v->parent_version_id,
        ])->values()->toArray();

        $edges = $versions
            ->filter(fn (SkillVersion $v) => $v->parent_version_id !== null)
            ->map(fn (SkillVersion $v) => [
                'from' => $v->parent_version_id,
                'to' => $v->id,
            ])->values()->toArray();

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    public function render(): View
    {
        return view('livewire.skills.skill-lineage-panel', [
            'lineageData' => $this->showPanel ? $this->getLineageData() : ['nodes' => [], 'edges' => []],
        ]);
    }
}
