<?php

namespace App\Livewire\Signals;

use App\Domain\Project\Models\ProjectRun;
use App\Domain\Signal\Models\Signal;
use Livewire\Component;
use Livewire\WithPagination;

class SignalBrowserPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $sourceTypeFilter = '';

    public string $sortBy = 'created_at';

    public string $sortDir = 'desc';

    public ?string $selectedSignalId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSourceTypeFilter(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if (! in_array($column, ['created_at', 'source_type', 'source_identifier'], true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'desc';
        }
    }

    public function selectSignal(?string $signalId): void
    {
        $this->selectedSignalId = $this->selectedSignalId === $signalId ? null : $signalId;
    }

    public function render()
    {
        $query = Signal::query();

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('source_identifier', 'ilike', '%'.$this->search.'%')
                    ->orWhereRaw('payload::text ilike ?', ['%'.$this->search.'%']);
            });
        }

        if ($this->sourceTypeFilter) {
            $query->where('source_type', $this->sourceTypeFilter);
        }

        $signals = $query->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25);

        $sourceTypes = Signal::select('source_type')
            ->distinct()
            ->orderBy('source_type')
            ->pluck('source_type');

        // Load detail for selected signal
        $selectedSignal = null;
        $triggerRuns = collect();
        if ($this->selectedSignalId) {
            $selectedSignal = Signal::with('entities')->find($this->selectedSignalId);
            if ($selectedSignal) {
                // Find project runs triggered by this signal
                $triggerRuns = ProjectRun::where('signal_id', $selectedSignal->id)
                    ->with('project')
                    ->latest()
                    ->limit(10)
                    ->get();
            }
        }

        return view('livewire.signals.signal-browser-page', [
            'signals' => $signals,
            'sourceTypes' => $sourceTypes,
            'selectedSignal' => $selectedSignal,
            'triggerRuns' => $triggerRuns,
        ])->layout('layouts.app', ['header' => 'Signals']);
    }
}
