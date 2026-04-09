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
        // Rely on TeamScope: cross-tenant executionId resolves to null,
        // so an attacker spoofing the Reactive prop cannot terminate
        // another team's crew run.
        $execution = CrewExecution::query()->find($this->executionId);

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
        $execution = CrewExecution::query()
            ->withCount('artifacts')
            ->with(['taskExecutions' => fn ($q) => $q->orderBy('sort_order'), 'taskExecutions.agent', 'chatMessages'])
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

        $chatMessages = $execution->chatMessages ?? collect();
        $isChatRoom = ($execution->config_snapshot['process_type'] ?? '') === 'chat_room';

        return view('livewire.crews.crew-execution-panel', [
            'execution' => $execution,
            'tasks' => $tasks,
            'progress' => $progress,
            'validatedCount' => $validated,
            'runningCount' => $tasks->filter(fn ($t) => $t->status->isActive())->count(),
            'failedCount' => $tasks->filter(fn ($t) => $t->status === CrewTaskStatus::QaFailed || $t->status === CrewTaskStatus::Failed)->count(),
            'chatMessages' => $chatMessages,
            'isChatRoom' => $isChatRoom,
        ]);
    }
}
