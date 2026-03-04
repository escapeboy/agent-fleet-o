<?php

namespace App\Livewire\Tools;

use App\Domain\Tool\Actions\ActivatePlatformToolAction;
use App\Domain\Tool\Actions\DeactivatePlatformToolAction;
use App\Domain\Tool\Actions\UpdateToolAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\TeamToolActivation;
use App\Domain\Tool\Models\Tool;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ToolListPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $typeFilter = '';

    #[Url]
    public string $statusFilter = '';

    public string $sortField = 'created_at';

    public string $sortDirection = 'desc';

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function toggleStatus(string $toolId): void
    {
        $tool = Tool::withoutGlobalScopes()->findOrFail($toolId);
        $teamId = auth()->user()->current_team_id;

        if ($tool->isPlatformTool()) {
            $activation = $tool->activationFor($teamId);
            if ($activation && $activation->isActive()) {
                app(DeactivatePlatformToolAction::class)->execute($tool, $teamId);
            } else {
                app(ActivatePlatformToolAction::class)->execute($tool, $teamId);
            }

            return;
        }

        $newStatus = $tool->status === ToolStatus::Active
            ? ToolStatus::Disabled
            : ToolStatus::Active;

        app(UpdateToolAction::class)->execute($tool, status: $newStatus);
    }

    public function render()
    {
        $query = Tool::query()->withCount('agents');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'ilike', "%{$this->search}%")
                    ->orWhere('description', 'ilike', "%{$this->search}%");
            });
        }

        if ($this->typeFilter) {
            $query->where('type', $this->typeFilter);
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        $query->orderBy($this->sortField, $this->sortDirection);

        $tools = $query->paginate(20);

        $platformToolIds = $tools->filter(fn ($t) => $t->isPlatformTool())->pluck('id');
        $teamId = auth()->user()->current_team_id;
        $activations = $platformToolIds->isNotEmpty()
            ? TeamToolActivation::where('team_id', $teamId)
                ->whereIn('tool_id', $platformToolIds)
                ->get()
                ->keyBy('tool_id')
            : collect();

        return view('livewire.tools.tool-list-page', [
            'tools' => $tools,
            'types' => ToolType::cases(),
            'statuses' => ToolStatus::cases(),
            'activations' => $activations,
            'canCreate' => true,
        ])->layout('layouts.app', ['header' => 'Tools']);
    }
}
