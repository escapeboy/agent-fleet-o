<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Budget\Actions\ReserveBudgetAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SpawnSubExperimentAction
{
    public function __construct(
        private readonly CreateExperimentAction $createExperiment,
        private readonly TransitionExperimentAction $transition,
        private readonly ReserveBudgetAction $reserveBudget,
    ) {}

    public function execute(
        Experiment $parent,
        string $goal,
        ?string $agentId = null,
        int $budgetAllocation = 0,
    ): Experiment {
        $config = $parent->orchestration_config ?? [];
        $maxDepth = $config['max_nesting_depth'] ?? config('experiments.orchestration.max_nesting_depth', 2);
        $maxChildren = $config['max_children'] ?? config('experiments.orchestration.max_children', 5);

        if ($parent->nesting_depth >= $maxDepth) {
            throw new \InvalidArgumentException(
                "Max nesting depth ({$maxDepth}) reached. Cannot spawn sub-experiment.",
            );
        }

        $activeChildCount = $parent->children()
            ->whereNotIn('status', array_map(fn ($s) => $s->value, ExperimentStatus::terminalStates()))
            ->count();

        if ($activeChildCount >= $maxChildren) {
            throw new \InvalidArgumentException(
                "Max children ({$maxChildren}) reached. Cannot spawn more sub-experiments.",
            );
        }

        return DB::transaction(function () use ($parent, $goal, $budgetAllocation) {
            // Allocate budget from parent if requested
            if ($budgetAllocation > 0 && $parent->budget_cap_credits) {
                $remaining = $parent->budget_cap_credits - $parent->budget_spent_credits;
                if ($budgetAllocation > $remaining) {
                    $budgetAllocation = $remaining;
                }
            }

            $child = $this->createExperiment->execute(
                userId: $parent->user_id,
                title: "[Sub] {$goal}",
                thesis: $goal,
                track: $parent->track->value,
                budgetCapCredits: $budgetAllocation > 0 ? $budgetAllocation : ($parent->budget_cap_credits ? (int) ($parent->budget_cap_credits * 0.2) : 2000),
                maxIterations: 1,
                maxOutboundCount: 0,
                constraints: [
                    'max_retries_per_stage' => 2,
                    'max_rejection_cycles' => 1,
                    'auto_approve' => true,
                ],
                teamId: $parent->team_id,
            );

            $child->update([
                'parent_experiment_id' => $parent->id,
                'nesting_depth' => $parent->nesting_depth + 1,
            ]);

            // Transition parent to AwaitingChildren if not already
            if ($parent->status !== ExperimentStatus::AwaitingChildren) {
                $this->transition->execute(
                    experiment: $parent,
                    toState: ExperimentStatus::AwaitingChildren,
                    reason: "Spawned sub-experiment: {$goal}",
                );
            }

            // Start child pipeline
            $this->transition->execute(
                experiment: $child,
                toState: ExperimentStatus::Scoring,
                reason: 'Sub-experiment started by parent orchestrator',
            );

            Log::info('SpawnSubExperimentAction: Child spawned', [
                'parent_id' => $parent->id,
                'child_id' => $child->id,
                'goal' => $goal,
                'nesting_depth' => $child->nesting_depth,
                'budget' => $child->budget_cap_credits,
            ]);

            return $child;
        });
    }
}
