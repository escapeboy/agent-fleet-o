<?php

namespace App\Livewire\Signals;

use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\Signal;
use Livewire\Component;
use Livewire\WithPagination;

class BugReportListPage extends Component
{
    use WithPagination;

    public string $projectFilter = '';

    public string $severityFilter = '';

    public string $statusFilter = '';

    public string $reporterFilter = '';

    public string $sortBy = 'created_at';

    public string $sortDir = 'desc';

    public function updatedProjectFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSeverityFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedReporterFilter(): void
    {
        $this->resetPage();
    }

    public function delete(string $id): void
    {
        $report = Signal::query()
            ->where('source_type', 'bug_report')
            ->find($id);

        $report?->delete();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'desc';
        }

        $this->resetPage();
    }

    public function render(): \Illuminate\View\View
    {
        $query = Signal::query()
            ->where('source_type', 'bug_report')
            ->when($this->projectFilter, fn ($q) => $q->where('project_key', $this->projectFilter))
            ->when($this->severityFilter, fn ($q) => $q->whereJsonContains('tags', $this->severityFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->reporterFilter, function ($q) {
                $q->where('payload->reporter_name', 'ilike', '%'.$this->reporterFilter.'%');
            })
            ->orderBy($this->sortBy, $this->sortDir);

        $reports = $query->paginate(20);

        $projects = Signal::query()
            ->where('source_type', 'bug_report')
            ->whereNotNull('project_key')
            ->distinct()
            ->pluck('project_key')
            ->sort()
            ->values();

        return view('livewire.signals.bug-report-list', [
            'reports' => $reports,
            'projects' => $projects,
            'statuses' => SignalStatus::cases(),
            'severities' => ['critical', 'major', 'minor', 'cosmetic'],
        ]);
    }
}
