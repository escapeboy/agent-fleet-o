<?php

namespace App\Livewire\Toolsets;

use App\Domain\Tool\Actions\CreateToolsetAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool;
use Livewire\Component;

class CreateToolsetForm extends Component
{
    public string $name = '';
    public string $description = '';
    public array $selectedToolIds = [];
    public array $tags = [];
    public string $tagsInput = '';

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'selectedToolIds' => 'array',
            'selectedToolIds.*' => 'uuid|exists:tools,id',
        ];
    }

    public function save(): void
    {
        $this->validate();

        $teamId = auth()->user()->current_team_id;
        $tags = array_filter(array_map('trim', explode(',', $this->tagsInput)));

        $toolset = app(CreateToolsetAction::class)->execute(
            teamId: $teamId,
            name: $this->name,
            description: $this->description,
            toolIds: $this->selectedToolIds,
            tags: array_values($tags),
            createdBy: auth()->id(),
        );

        $this->redirect(route('toolsets.show', $toolset), navigate: true);
    }

    public function render()
    {
        $teamId = auth()->user()->current_team_id;

        return view('livewire.toolsets.create-toolset-form', [
            'availableTools' => Tool::where('status', ToolStatus::Active->value)->orderBy('name')->get(),
        ])->layout('layouts.app', ['header' => 'Create Toolset']);
    }
}
