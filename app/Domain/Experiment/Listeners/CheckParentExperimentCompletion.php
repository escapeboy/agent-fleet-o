<?php

namespace App\Domain\Experiment\Listeners;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckParentExperimentCompletion
{
    public function handle(ExperimentTransitioned $event): void
    {
        $child = $event->experiment;

        // Only act when a child experiment reaches a terminal state
        if (! $child->parent_experiment_id || ! $event->toState->isTerminal()) {
            return;
        }

        $parent = Experiment::withoutGlobalScopes()->find($child->parent_experiment_id);

        if (! $parent || $parent->status !== ExperimentStatus::AwaitingChildren) {
            return;
        }

        $siblings = Experiment::withoutGlobalScopes()
            ->where('parent_experiment_id', $parent->id)
            ->get();

        $allTerminal = $siblings->every(fn (Experiment $s) => $s->status->isTerminal());

        if (! $allTerminal) {
            Log::debug('CheckParentExperimentCompletion: Not all children terminal yet', [
                'parent_id' => $parent->id,
                'child_id' => $child->id,
                'children_count' => $siblings->count(),
                'terminal_count' => $siblings->filter(fn ($s) => $s->status->isTerminal())->count(),
            ]);

            return;
        }

        // All children done — evaluate based on failure policy
        $completedChildren = $siblings->where('status', ExperimentStatus::Completed);
        $failedChildren = $siblings->filter(fn ($s) => $s->status !== ExperimentStatus::Completed && $s->status->isTerminal());

        $config = $parent->orchestration_config ?? [];
        $failurePolicy = $config['failure_policy'] ?? config('experiments.orchestration.default_failure_policy', 'continue_on_partial');

        Log::info('CheckParentExperimentCompletion: All children terminal', [
            'parent_id' => $parent->id,
            'completed' => $completedChildren->count(),
            'failed' => $failedChildren->count(),
            'failure_policy' => $failurePolicy,
        ]);

        // Aggregate child outputs into parent metadata
        $childOutputs = $siblings->mapWithKeys(function (Experiment $sibling) {
            $lastStage = $sibling->stages()->latest()->first();

            return [$sibling->id => [
                'title' => $sibling->title,
                'status' => $sibling->status->value,
                'output' => $lastStage?->output_snapshot,
            ]];
        })->toArray();

        // Decide what to do based on failure policy
        if ($failedChildren->isNotEmpty()) {
            match ($failurePolicy) {
                'abort_all' => $this->abortParent($parent, $childOutputs),
                'retry_failed' => $this->retryFailed($parent, $failedChildren, $childOutputs),
                default => $this->continueParent($parent, $childOutputs), // continue_on_partial
            };
        } else {
            $this->continueParent($parent, $childOutputs);
        }
    }

    private function continueParent(Experiment $parent, array $childOutputs): void
    {
        DB::transaction(function () use ($parent, $childOutputs) {
            // Store child results in parent's constraints for downstream stages
            $constraints = $parent->constraints ?? [];
            $constraints['child_outputs'] = $childOutputs;
            $parent->update(['constraints' => $constraints]);

            app(TransitionExperimentAction::class)->execute(
                experiment: $parent,
                toState: ExperimentStatus::Executing,
                reason: 'All children completed, continuing parent pipeline',
                metadata: ['child_outputs' => array_keys($childOutputs)],
            );
        });
    }

    private function abortParent(Experiment $parent, array $childOutputs): void
    {
        $failedTitles = collect($childOutputs)
            ->filter(fn ($o) => $o['status'] !== 'completed')
            ->pluck('title')
            ->implode(', ');

        app(TransitionExperimentAction::class)->execute(
            experiment: $parent,
            toState: ExperimentStatus::Killed,
            reason: "Abort policy: children failed — {$failedTitles}",
            metadata: ['child_outputs' => array_keys($childOutputs)],
        );
    }

    private function retryFailed(Experiment $parent, $failedChildren, array $childOutputs): void
    {
        $maxRetries = ($parent->orchestration_config ?? [])['max_retries'] ?? 1;

        foreach ($failedChildren as $failed) {
            $retryCount = Experiment::withoutGlobalScopes()
                ->where('parent_experiment_id', $parent->id)
                ->where('title', $failed->title)
                ->count();

            if ($retryCount <= $maxRetries) {
                try {
                    app(TransitionExperimentAction::class)->execute(
                        experiment: $failed,
                        toState: ExperimentStatus::Scoring,
                        reason: "Retry policy: re-running failed child (attempt {$retryCount})",
                    );

                    return; // Wait for retry to complete
                } catch (\Throwable $e) {
                    Log::warning('CheckParentExperimentCompletion: Failed to retry child', [
                        'child_id' => $failed->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // All retries exhausted — continue with partial results
        $this->continueParent($parent, $childOutputs);
    }
}
