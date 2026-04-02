<?php

namespace App\Domain\Experiment\Enums;

enum StuckPattern: string
{
    case StateOscillation = 'state_oscillation';
    case RepeatedStageFailure = 'repeated_stage_failure';
    case ToolCallLoop = 'tool_call_loop';
    case ProgressStall = 'progress_stall';
    case BudgetDrain = 'budget_drain';

    public function severity(): string
    {
        return match ($this) {
            self::StateOscillation => 'medium',
            self::RepeatedStageFailure => 'high',
            self::ToolCallLoop => 'high',
            self::ProgressStall => 'low',
            self::BudgetDrain => 'critical',
        };
    }

    public function defaultAction(): string
    {
        return match ($this) {
            self::StateOscillation => 'pause',
            self::RepeatedStageFailure => 'pause',
            self::ToolCallLoop => 'pause',
            self::ProgressStall => 'notify',
            self::BudgetDrain => 'pause',
        };
    }
}
