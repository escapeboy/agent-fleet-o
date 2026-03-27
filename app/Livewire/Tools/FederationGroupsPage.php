<?php

namespace App\Livewire\Tools;

use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Models\ToolFederationGroup;
use Livewire\Component;

class FederationGroupsPage extends Component
{
    public string $name = '';

    public string $description = '';

    public array $selectedToolIds = [];

    public bool $showCreateForm = false;

    public function create(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
        ]);

        $teamId = current_team()->id;

        $validIds = Tool::query()
            ->where('team_id', $teamId)
            ->whereIn('id', $this->selectedToolIds)
            ->pluck('id')
            ->toArray();

        ToolFederationGroup::create([
            'team_id' => $teamId,
            'name' => $this->name,
            'description' => $this->description,
            'tool_ids' => $validIds,
            'is_active' => true,
        ]);

        $this->reset(['name', 'description', 'selectedToolIds', 'showCreateForm']);
        session()->flash('success', 'Federation group created.');
    }

    public function delete(string $groupId): void
    {
        ToolFederationGroup::where('team_id', current_team()->id)
            ->findOrFail($groupId)
            ->delete();

        session()->flash('success', 'Group deleted.');
    }

    public function toggleTool(string $toolId): void
    {
        if (in_array($toolId, $this->selectedToolIds)) {
            $this->selectedToolIds = array_values(array_diff($this->selectedToolIds, [$toolId]));
        } else {
            $this->selectedToolIds[] = $toolId;
        }
    }

    public function render()
    {
        $teamId = current_team()->id;

        return view('livewire.tools.federation-groups-page', [
            'groups' => ToolFederationGroup::where('team_id', $teamId)
                ->orderBy('name')
                ->get(),
            'availableTools' => Tool::query()
                ->where('team_id', $teamId)
                ->where('status', ToolStatus::Active)
                ->orderBy('name')
                ->get(),
        ])->layout('layouts.app', ['header' => 'Tool Federation Groups']);
    }
}
