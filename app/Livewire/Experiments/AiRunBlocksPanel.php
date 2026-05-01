<?php

namespace App\Livewire\Experiments;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\View\View;
use Livewire\Component;

class AiRunBlocksPanel extends Component
{
    public string $experimentId;

    /** @var array<string> Expanded block IDs */
    public array $expandedBlocks = [];

    public function mount(string $experimentId): void
    {
        Experiment::query()->findOrFail($experimentId);
        $this->experimentId = $experimentId;
    }

    public function toggleBlock(string $runId): void
    {
        if (in_array($runId, $this->expandedBlocks)) {
            $this->expandedBlocks = array_values(array_filter($this->expandedBlocks, fn ($id) => $id !== $runId));
        } else {
            $this->expandedBlocks[] = $runId;
        }
    }

    public function render(): View
    {
        $runs = AiRun::withoutGlobalScopes()
            ->where('experiment_id', $this->experimentId)
            ->orderBy('created_at')
            ->get();

        return view('livewire.experiments.ai-run-blocks-panel', [
            'runs' => $runs,
        ]);
    }
}
