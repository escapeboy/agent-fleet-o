<?php

namespace App\Livewire\Signals;

use App\Domain\Signal\Actions\UpdateSignalStatusAction;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Exceptions\InvalidSignalTransitionException;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class BugReportListPage extends Component
{
    use WithPagination;

    public string $projectFilter = '';

    public string $severityFilter = '';

    public string $statusFilter = '';

    public string $reporterFilter = '';

    public string $search = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $sortBy = 'created_at';

    public string $sortDir = 'desc';

    /** @var array<int, string> */
    private const SORTABLE_COLUMNS = ['created_at', 'status', 'project_key', 'suggested_type'];

    /** @var array<string, string> */
    private const SORTABLE_JSON = [
        'severity' => "payload->>'severity'",
        'reporter' => "payload->>'reporter_name'",
    ];

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

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
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

    public function reopen(string $id): void
    {
        $report = Signal::query()
            ->where('source_type', 'bug_report')
            ->find($id);

        if (! $report) {
            return;
        }

        try {
            app(UpdateSignalStatusAction::class)->execute(
                signal: $report,
                newStatus: SignalStatus::Triaged,
                actor: auth()->user(),
            );
        } catch (InvalidSignalTransitionException) {
            $this->addError('reopen', 'Cannot reopen this report.');
        }
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

    public function render(): View
    {
        $dir = $this->sortDir === 'asc' ? 'asc' : 'desc';

        $query = Signal::query()
            ->where('source_type', 'bug_report')
            ->when($this->projectFilter, fn ($q) => $q->where('project_key', $this->projectFilter))
            ->when($this->severityFilter, fn ($q) => $q->whereJsonContains('tags', $this->severityFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->reporterFilter, fn ($q) => $q->where('payload->reporter_name', 'ilike', '%'.$this->reporterFilter.'%'))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $op = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $q->where(function ($inner) use ($term, $op) {
                    $inner->where('payload->title', $op, $term)
                        ->orWhere('payload->description', $op, $term)
                        ->orWhere('payload->url', $op, $term)
                        ->orWhere('payload->reporter_name', $op, $term);
                });
            })
            ->when($this->dateFrom !== '', fn ($q) => $q->where('received_at', '>=', $this->dateFrom.' 00:00:00'))
            ->when($this->dateTo !== '', fn ($q) => $q->where('received_at', '<=', $this->dateTo.' 23:59:59'));

        if (array_key_exists($this->sortBy, self::SORTABLE_JSON)) {
            $rawCol = self::SORTABLE_JSON[$this->sortBy];
            $query->orderByRaw("{$rawCol} {$dir}");
        } elseif (in_array($this->sortBy, self::SORTABLE_COLUMNS, true)) {
            $query->orderBy($this->sortBy, $dir);
        } else {
            $query->orderBy('created_at', 'desc');
        }

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
