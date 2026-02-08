<?php

namespace App\Domain\Experiment\Enums;

enum ExperimentStatus: string
{
    case Draft = 'draft';
    case SignalDetected = 'signal_detected';
    case Scoring = 'scoring';
    case ScoringFailed = 'scoring_failed';
    case Planning = 'planning';
    case PlanningFailed = 'planning_failed';
    case Building = 'building';
    case BuildingFailed = 'building_failed';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Executing = 'executing';
    case ExecutionFailed = 'execution_failed';
    case CollectingMetrics = 'collecting_metrics';
    case Evaluating = 'evaluating';
    case Iterating = 'iterating';
    case Paused = 'paused';
    case Completed = 'completed';
    case Killed = 'killed';
    case Discarded = 'discarded';
    case Expired = 'expired';

    public static function terminalStates(): array
    {
        return [self::Completed, self::Killed, self::Discarded, self::Expired];
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminalStates());
    }

    public function isPausable(): bool
    {
        return !$this->isTerminal() && $this !== self::Paused;
    }

    public function isActive(): bool
    {
        return !$this->isTerminal() && $this !== self::Paused;
    }

    public function isFailed(): bool
    {
        return in_array($this, [
            self::ScoringFailed,
            self::PlanningFailed,
            self::BuildingFailed,
            self::ExecutionFailed,
        ]);
    }
}
