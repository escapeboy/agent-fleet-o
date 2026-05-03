<?php

namespace App\Livewire\Toolsets;

use App\Domain\Tool\Actions\DeleteToolsetAction;
use App\Domain\Tool\Actions\UpdateToolsetAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Models\Toolset;
use Livewire\Component;

class ToolsetDetailPage extends Component
{
    public Toolset $toolset;

    public bool $editing = false;

    public string $name = '';

    public string $description = '';

    public array $selectedToolIds = [];

    public string $tagsInput = '';

    public function mount(Toolset $toolset): void
    {
        $this->toolset = $toolset;
        $this->resetForm();
    }

    public function startEdit(): void
    {
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->resetForm();
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'selectedToolIds' => 'array',
            'selectedToolIds.*' => 'uuid|exists:tools,id',
        ]);

        $tags = array_filter(array_map('trim', explode(',', $this->tagsInput)));

        app(UpdateToolsetAction::class)->execute($this->toolset, [
            'name' => $this->name,
            'description' => $this->description,
            'tool_ids' => $this->selectedToolIds,
            'tags' => array_values($tags),
        ]);

        $this->toolset = $this->toolset->fresh();
        $this->editing = false;
        $this->resetForm();
    }

    public function delete(): void
    {
        app(DeleteToolsetAction::class)->execute($this->toolset);
        $this->redirect(route('toolsets.index'), navigate: true);
    }

    private function resetForm(): void
    {
        $this->name = $this->toolset->name;
        $this->description = $this->toolset->description ?? '';
        $this->selectedToolIds = $this->toolset->tool_ids ?? [];
        $this->tagsInput = implode(', ', $this->toolset->tags ?? []);
    }

    public function render()
    {
        return view('livewire.toolsets.toolset-detail-page', [
            'tools' => $this->toolset->tools(),
            'availableTools' => Tool::where('status', ToolStatus::Active->value)->orderBy('name')->get(),
            'agents' => $this->toolset->agents,
        ])->layout('layouts.app', ['header' => $this->toolset->name]);
    }
}
