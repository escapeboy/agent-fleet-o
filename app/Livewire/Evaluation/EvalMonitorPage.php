<?php

namespace App\Livewire\Evaluation;

use App\Domain\Evaluation\Models\EvaluationMonitorSnapshot;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class EvalMonitorPage extends Component
{
    use WithPagination;

    #[Url]
    public string $period = '30d';

    public function updatedPeriod(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = EvaluationMonitorSnapshot::with('dataset')->latest('created_at');

        $since = match ($this->period) {
            '24h' => Carbon::now()->subDay(),
            '7d' => Carbon::now()->subDays(7),
            '30d' => Carbon::now()->subDays(30),
            default => null,
        };

        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        return view('livewire.evaluation.eval-monitor-page', [
            'snapshots' => $query->paginate(50),
        ])->layout('layouts.app', ['header' => 'Eval Monitor']);
    }
}
