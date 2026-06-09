<?php

namespace App\Livewire\Testing;

use App\Domain\Testing\Models\TestRun;
use App\Domain\Testing\Models\TestSuite;
use Livewire\Component;
use Livewire\WithPagination;

class TestSuiteDetailPage extends Component
{
    use WithPagination;

    public string $suiteId;

    public function mount(string $suite): void
    {
        // TeamScope refuses cross-tenant ids; abort 404 (HttpException) on a
        // foreign/missing suite so Livewire renders a 404 rather than throwing.
        $model = TestSuite::query()->find($suite);
        abort_if($model === null, 404);
        $this->suiteId = $model->id;
    }

    public function render()
    {
        $suite = TestSuite::query()->with('project')->findOrFail($this->suiteId);

        // TestRun has no team_id of its own; constrain through the team-scoped
        // suite relation so a foreign suite's runs can never surface here.
        $runs = TestRun::query()
            ->where('test_suite_id', $suite->id)
            ->with('experiment')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('livewire.testing.test-suite-detail-page', [
            'suite' => $suite,
            'runs' => $runs,
        ])->layout('layouts.app', ['header' => 'Test Suite']);
    }
}
