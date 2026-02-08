<?php

namespace App\Livewire\Audit;

use App\Domain\Audit\Models\AuditEntry;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class AuditLogPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $eventFilter = '';

    public ?string $expandedEntryId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedEventFilter(): void
    {
        $this->resetPage();
    }

    public function toggleEntry(string $entryId): void
    {
        $this->expandedEntryId = $this->expandedEntryId === $entryId ? null : $entryId;
    }

    public function render()
    {
        $query = AuditEntry::with('user')->latest('created_at');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('event', 'ilike', "%{$this->search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'ilike', "%{$this->search}%"));
            });
        }

        if ($this->eventFilter) {
            $query->where('event', 'like', "{$this->eventFilter}.%");
        }

        $eventTypes = AuditEntry::selectRaw("split_part(event, '.', 1) as category")
            ->distinct()
            ->pluck('category')
            ->filter()
            ->sort()
            ->values();

        return view('livewire.audit.audit-log-page', [
            'entries' => $query->paginate(50),
            'eventTypes' => $eventTypes,
        ])->layout('layouts.app', ['header' => 'Audit Log']);
    }
}
