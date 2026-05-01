<?php

namespace App\Domain\Crew\Actions;

use App\Domain\Crew\Models\CrewExecution;

class ComputeCrewQualityAction
{
    public function execute(CrewExecution $execution): array
    {
        $tasks = $execution->taskExecutions()->get();

        $total = $tasks->count();

        if ($total === 0) {
            return ['coherence' => 0, 'efficiency' => 0, 'diversity' => 0, 'quality' => 0, 'overall' => 0, 'computed_at' => now()->toIso8601String()];
        }

        $validated = $tasks->filter(fn ($t) => $t->isValidated())->count();
        $coherence = round($validated / $total, 4);

        $totalRetries = $tasks->sum(fn ($t) => max(0, $t->attempt_number - 1));
        $efficiency = max(0, round(1 - ($totalRetries / max($total, 1)), 4));

        $distinctAgents = $tasks->whereNotNull('agent_id')->pluck('agent_id')->unique()->count();
        $diversity = round($distinctAgents / max($total, 1), 4);

        $validatedTasks = $tasks->filter(fn ($t) => $t->isValidated() && $t->qa_score !== null);
        $quality = $validatedTasks->isNotEmpty()
            ? round($validatedTasks->avg('qa_score'), 4)
            : 0.0;

        $overall = round(0.3 * $coherence + 0.3 * $quality + 0.2 * $efficiency + 0.2 * $diversity, 4);

        $dims = compact('coherence', 'efficiency', 'diversity', 'quality', 'overall');
        $dims['computed_at'] = now()->toIso8601String();

        $execution->update(['quality_dimensions' => $dims]);

        return $dims;
    }
}
