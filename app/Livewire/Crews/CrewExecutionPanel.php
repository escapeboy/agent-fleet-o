<?php

namespace App\Livewire\Crews;

use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\CrewExecution;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class CrewExecutionPanel extends Component
{
    #[Reactive]
    public string $executionId;

    public function terminateExecution(): void
    {
        $execution = CrewExecution::withoutGlobalScopes()->find($this->executionId);

        if ($execution && $execution->status->isActive()) {
            $execution->update([
                'status' => CrewExecutionStatus::Terminated,
                'error_message' => 'Terminated by user.',
                'completed_at' => now(),
            ]);
        }
    }

    public function render()
    {
        $execution = CrewExecution::withoutGlobalScopes()
            ->withCount('artifacts')
            ->with(['taskExecutions' => fn ($q) => $q->orderBy('sort_order'), 'taskExecutions.agent'])
            ->find($this->executionId);

        if (! $execution) {
            return view('livewire.crews.crew-execution-panel', [
                'execution' => null,
                'tasks' => collect(),
                'progress' => 0,
            ]);
        }

        $tasks = $execution->taskExecutions;
        $total = $tasks->count();
        $validated = $tasks->filter(fn ($t) => $t->isValidated())->count();
        $progress = $total > 0 ? round(($validated / $total) * 100) : 0;

        return view('livewire.crews.crew-execution-panel', [
            'execution' => $execution,
            'tasks' => $tasks,
            'progress' => $progress,
            'validatedCount' => $validated,
            'runningCount' => $tasks->filter(fn ($t) => $t->status->isActive())->count(),
            'failedCount' => $tasks->filter(fn ($t) => $t->status === CrewTaskStatus::QaFailed || $t->status === CrewTaskStatus::Failed)->count(),
        ]);
    }
}
