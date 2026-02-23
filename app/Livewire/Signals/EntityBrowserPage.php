<?php

namespace App\Livewire\Signals;

use App\Domain\Signal\Models\Entity;
use Livewire\Component;
use Livewire\WithPagination;

class EntityBrowserPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $typeFilter = '';

    public string $sortBy = 'mention_count';

    public string $sortDir = 'desc';

    public ?string $selectedEntityId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'desc';
        }
    }

    public function selectEntity(?string $entityId): void
    {
        $this->selectedEntityId = $this->selectedEntityId === $entityId ? null : $entityId;
    }

    public function render()
    {
        $query = Entity::query();

        if ($this->search) {
            $query->where('name', 'ilike', '%'.$this->search.'%');
        }

        if ($this->typeFilter) {
            $query->where('type', $this->typeFilter);
        }

        $entities = $query->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25);

        $entityTypes = Entity::select('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type');

        $selectedEntity = null;
        $linkedSignals = collect();
        if ($this->selectedEntityId) {
            $selectedEntity = Entity::find($this->selectedEntityId);
            if ($selectedEntity) {
                $linkedSignals = $selectedEntity->signals()
                    ->latest()
                    ->limit(20)
                    ->get();
            }
        }

        return view('livewire.signals.entity-browser-page', [
            'entities' => $entities,
            'entityTypes' => $entityTypes,
            'selectedEntity' => $selectedEntity,
            'linkedSignals' => $linkedSignals,
        ])->layout('layouts.app', ['header' => 'Entity Browser']);
    }
}
