<?php

namespace App\Livewire\ErrorModes;

use App\Domain\ErrorMode\Actions\AssignErrorModeLeverAction;
use App\Domain\ErrorMode\Enums\ErrorModeLever;
use App\Domain\ErrorMode\Models\ErrorMode;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

class ErrorModeCatalogPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $leverFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedLeverFilter(): void
    {
        $this->resetPage();
    }

    public function assignLever(string $errorModeId, string $lever): void
    {
        Gate::authorize('edit-content');

        $teamId = auth()->user()->current_team_id;

        app(AssignErrorModeLeverAction::class)->execute(
            teamId: $teamId,
            errorModeId: $errorModeId,
            lever: ErrorModeLever::from($lever),
        );

        session()->flash('message', 'Lever assigned.');
    }

    public function render()
    {
        // Relies on the TeamScope global scope for tenant isolation.
        $query = ErrorMode::query()
            ->orderByDesc('occurrence_count')
            ->orderByDesc('last_seen_at');

        if ($this->search !== '') {
            $query->whereRaw('lower(name) like ?', ['%'.mb_strtolower($this->search).'%']);
        }

        if ($this->leverFilter !== '') {
            $query->where('lever', $this->leverFilter);
        }

        return view('livewire.error-modes.error-mode-catalog-page', [
            'errorModes' => $query->paginate(20),
            'levers' => ErrorModeLever::cases(),
        ])->layout('layouts.app', ['header' => 'Error Mode Catalog']);
    }
}
