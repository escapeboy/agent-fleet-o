<?php

namespace App\Livewire\Tools;

use App\Domain\Tool\Actions\DeleteToolAction;
use App\Domain\Tool\Actions\UpdateToolAction;
use App\Domain\Tool\Enums\ToolStatus;
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

    public function mount(Tool $tool): void
    {
        $this->tool = $tool;
    }

    public function toggleStatus(): void
    {
        $newStatus = $this->tool->status === ToolStatus::Active
            ? ToolStatus::Disabled
            : ToolStatus::Active;

        app(UpdateToolAction::class)->execute($this->tool, status: $newStatus);
        $this->tool->refresh();
    }

    public function startEdit(): void
    {
        $this->editName = $this->tool->name;
        $this->editDescription = $this->tool->description ?? '';
        $this->editTimeout = $this->tool->settings['timeout'] ?? 30;
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
            'editTimeout' => 'integer|min:1|max:300',
        ]);

        app(UpdateToolAction::class)->execute(
            $this->tool,
            name: $this->editName,
            description: $this->editDescription ?: null,
            settings: ['timeout' => $this->editTimeout],
        );

        $this->tool->refresh();
        $this->editing = false;

        session()->flash('message', 'Tool updated successfully.');
    }

    public function deleteTool(): void
    {
        app(DeleteToolAction::class)->execute($this->tool);

        session()->flash('message', 'Tool deleted.');
        $this->redirect(route('tools.index'));
    }

    public function render()
    {
        $agents = $this->tool->agents()->get();

        return view('livewire.tools.tool-detail-page', [
            'agents' => $agents,
        ])->layout('layouts.app', ['header' => $this->tool->name]);
    }
}
