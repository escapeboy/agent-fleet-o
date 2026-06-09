<?php

namespace App\Livewire\Evaluation;

use App\Domain\Evaluation\Enums\DriftSignalType;
use App\Domain\Evaluation\Models\DriftSignal;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class DriftSignalsPage extends Component
{
    use WithPagination;

    #[Url]
    public string $typeFilter = '';

    #[Url]
    public string $breachFilter = '';

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedBreachFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = DriftSignal::query()->latest('detected_at');

        if ($this->typeFilter) {
            $query->where('signal_type', $this->typeFilter);
        }

        if ($this->breachFilter === 'breached') {
            $query->where('breached', true);
        } elseif ($this->breachFilter === 'ok') {
            $query->where('breached', false);
        }

        return view('livewire.evaluation.drift-signals-page', [
            'signals' => $query->paginate(50),
            'signalTypes' => DriftSignalType::cases(),
        ])->layout('layouts.app', ['header' => 'Drift Signals']);
    }
}
