<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Models\ReasoningBankEntry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ReasoningBankPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public ?string $expandedId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function toggleExpand(string $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function render(): View
    {
        // Team isolation relies on ReasoningBankEntry's TeamScope global scope
        // (BelongsToTeam). Do NOT swap to withoutGlobalScopes()->when($teamId),
        // which leaks cross-tenant rows when the team is null.
        $query = ReasoningBankEntry::query()
            ->with('experiment:id,title')
            ->latest('created_at');

        if ($this->search) {
            $term = '%'.mb_strtolower($this->search).'%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('lower(goal_text) like ?', [$term])
                    ->orWhereRaw('lower(outcome_summary) like ?', [$term]);
            });
        }

        return view('livewire.experiments.reasoning-bank-page', [
            'entries' => $query->paginate(30),
        ])->layout('layouts.app', ['header' => 'Reasoning Bank']);
    }
}
