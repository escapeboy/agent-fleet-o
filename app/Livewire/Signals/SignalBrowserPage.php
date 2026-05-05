<?php

namespace App\Livewire\Signals;

use App\Domain\Project\Models\ProjectRun;
use App\Domain\Signal\Actions\AssignSignalAction;
use App\Domain\Signal\Models\Signal;
use App\Models\User;
use Livewire\Attributes\Validate;
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

    public bool $assignedToMeFilter = false;

    public bool $showAssignModal = false;

    public ?string $assignModalSignalId = null;

    #[Validate('nullable|string|uuid')]
    public ?string $assignUserId = null;

    #[Validate('nullable|string|max:2000')]
    public ?string $assignReason = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSourceTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAssignedToMeFilter(): void
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

    public function openAssignModal(string $signalId): void
    {
        $this->assignModalSignalId = $signalId;
        $signal = Signal::where('team_id', auth()->user()->current_team_id)->find($signalId);
        $this->assignUserId = $signal?->assigned_user_id;
        $this->assignReason = null;
        $this->showAssignModal = true;
    }

    public function submitAssign(): void
    {
        $this->validate();

        $signal = Signal::where('team_id', auth()->user()->current_team_id)->find($this->assignModalSignalId);

        if (! $signal) {
            $this->showAssignModal = false;

            return;
        }

        $assignee = $this->assignUserId ? User::find($this->assignUserId) : null;
        $actor = auth()->user();

        try {
            app(AssignSignalAction::class)->execute(
                signal: $signal,
                assignee: $assignee,
                actor: $actor,
                reason: $this->assignReason ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->addError('assignUserId', $e->getMessage());

            return;
        }

        $this->showAssignModal = false;
        $this->assignModalSignalId = null;
        $this->assignUserId = null;
        $this->assignReason = null;

        $this->dispatch('$refresh');
    }

    public function render()
    {
        $query = Signal::query()->with('assignedUser');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('source_identifier', 'ilike', '%'.$this->search.'%')
                    ->orWhereRaw('payload::text ilike ?', ['%'.$this->search.'%']);
            });
        }

        if ($this->sourceTypeFilter) {
            $query->where('source_type', $this->sourceTypeFilter);
        }

        if ($this->assignedToMeFilter) {
            $query->where('assigned_user_id', auth()->id());
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
            $selectedSignal = Signal::with(['entities', 'assignedUser'])->find($this->selectedSignalId);
            if ($selectedSignal) {
                // Find project runs triggered by this signal
                $triggerRuns = ProjectRun::where('signal_id', $selectedSignal->id)
                    ->with('project')
                    ->latest()
                    ->limit(10)
                    ->get();
            }
        }

        $teamMembers = User::whereHas('teams', fn ($q) => $q->where('teams.id', auth()->user()->current_team_id))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('livewire.signals.signal-browser-page', [
            'signals' => $signals,
            'sourceTypes' => $sourceTypes,
            'selectedSignal' => $selectedSignal,
            'triggerRuns' => $triggerRuns,
            'teamMembers' => $teamMembers,
        ])->layout('layouts.app', ['header' => 'Signals']);
    }
}
