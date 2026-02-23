<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Support\Facades\Log;

class KillExperimentAction
{
    public function __construct(
        private readonly TransitionExperimentAction $transition,
    ) {}

    public function execute(Experiment $experiment, ?string $actorId = null, string $reason = 'Manual kill'): Experiment
    {
        $result = $this->transition->execute(
            experiment: $experiment,
            toState: ExperimentStatus::Killed,
            reason: $reason,
            actorId: $actorId,
        );

        // Cascade kill to all active children
        $activeChildren = $experiment->children()
            ->whereNotIn('status', array_map(fn ($s) => $s->value, ExperimentStatus::terminalStates()))
            ->get();

        foreach ($activeChildren as $child) {
            Log::info('KillExperimentAction: Cascading kill to child', [
                'parent_id' => $experiment->id,
                'child_id' => $child->id,
            ]);

            $this->execute($child, $actorId, "Cascade kill from parent {$experiment->id}");
        }

        return $result;
    }
}
