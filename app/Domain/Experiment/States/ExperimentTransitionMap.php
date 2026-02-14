<?php

namespace App\Domain\Experiment\States;

use App\Domain\Experiment\Enums\ExperimentStatus;

class ExperimentTransitionMap
{
    /**
     * Explicit forward transitions: from_state => [to_state, ...]
     */
    private const FORWARD_TRANSITIONS = [
        'draft' => ['scoring', 'planning', 'executing'],
        'signal_detected' => ['scoring'],
        'scoring' => ['planning', 'scoring_failed', 'discarded'],
        'scoring_failed' => ['scoring', 'killed'],
        'planning' => ['building', 'planning_failed'],
        'planning_failed' => ['planning', 'killed'],
        'building' => ['awaiting_approval', 'building_failed'],
        'building_failed' => ['building', 'killed'],
        'awaiting_approval' => ['approved', 'rejected', 'expired'],
        'rejected' => ['planning', 'killed'],
        'approved' => ['executing'],
        'executing' => ['collecting_metrics', 'completed', 'execution_failed'],
        'execution_failed' => ['executing', 'killed'],
        'collecting_metrics' => ['evaluating'],
        'evaluating' => ['iterating', 'completed', 'killed'],
        'iterating' => ['planning', 'executing'],
    ];

    /**
     * States that can be paused (all non-terminal, non-paused).
     */
    private const PAUSABLE_STATES = [
        'scoring', 'planning', 'building', 'executing',
        'collecting_metrics', 'evaluating', 'iterating',
    ];

    /**
     * States that can be killed (all non-terminal).
     */
    private const KILLABLE_STATES = [
        'draft', 'signal_detected', 'scoring', 'scoring_failed',
        'planning', 'planning_failed', 'building', 'building_failed',
        'awaiting_approval', 'approved', 'rejected', 'executing',
        'execution_failed', 'collecting_metrics', 'evaluating',
        'iterating', 'paused',
    ];

    public static function canTransition(ExperimentStatus $from, ExperimentStatus $to): bool
    {
        // Pause transition
        if ($to === ExperimentStatus::Paused) {
            return in_array($from->value, self::PAUSABLE_STATES);
        }

        // Kill transition
        if ($to === ExperimentStatus::Killed) {
            return in_array($from->value, self::KILLABLE_STATES);
        }

        // Resume from paused: handled separately (goes to paused_from_status)
        if ($from === ExperimentStatus::Paused && $to !== ExperimentStatus::Killed) {
            return in_array($to->value, self::PAUSABLE_STATES);
        }

        // Forward transitions
        $allowed = self::FORWARD_TRANSITIONS[$from->value] ?? [];

        return in_array($to->value, $allowed);
    }

    public static function allowedTransitions(ExperimentStatus $from): array
    {
        $transitions = [];

        // Forward transitions
        $forward = self::FORWARD_TRANSITIONS[$from->value] ?? [];
        foreach ($forward as $state) {
            $transitions[] = ExperimentStatus::from($state);
        }

        // Pause
        if (in_array($from->value, self::PAUSABLE_STATES)) {
            $transitions[] = ExperimentStatus::Paused;
        }

        // Kill
        if (in_array($from->value, self::KILLABLE_STATES)) {
            $transitions[] = ExperimentStatus::Killed;
        }

        // Resume from paused
        if ($from === ExperimentStatus::Paused) {
            foreach (self::PAUSABLE_STATES as $state) {
                $transitions[] = ExperimentStatus::from($state);
            }
            $transitions[] = ExperimentStatus::Killed;
        }

        return array_unique($transitions, SORT_REGULAR);
    }
}
