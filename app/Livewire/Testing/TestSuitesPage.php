<?php

namespace App\Livewire\Testing;

use App\Domain\Testing\Enums\TestStrategy;
use App\Domain\Testing\Models\TestSuite;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class TestSuitesPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $strategyFilter = '';

    #[Url]
    public string $activeFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStrategyFilter(): void
    {
        $this->resetPage();
    }

    public function updatedActiveFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        // Rely on the TeamScope global scope for tenant isolation — do NOT
        // use withoutGlobalScopes()->when($teamId, ...), which leaks across
        // tenants when the team id is null.
        $query = TestSuite::query()
            ->with('project')
            ->withCount('testRuns');

        if ($this->search !== '') {
            $query->whereRaw('lower(name) like ?', ['%'.mb_strtolower($this->search).'%']);
        }

        if ($this->strategyFilter !== '') {
            $query->where('test_strategy', $this->strategyFilter);
        }

        if ($this->activeFilter !== '') {
            $query->where('is_active', $this->activeFilter === 'active');
        }

        $suites = $query->orderByDesc('created_at')->paginate(20);

        return view('livewire.testing.test-suites-page', [
            'suites' => $suites,
            'strategies' => TestStrategy::cases(),
        ])->layout('layouts.app', ['header' => 'Test Suites']);
    }
}
