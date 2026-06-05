<?php

namespace App\Domain\Evaluation\Enums;

/**
 * The four drift signals from the SwirlAI flywheel (Drift capture stage).
 */
enum DriftSignalType: string
{
    case InputDistributionShift = 'input_distribution_shift';
    case EvalScoreDecay = 'eval_score_decay';
    case ThumbsDownRate = 'thumbs_down_rate';
    case LatencyCostSpike = 'latency_cost_spike';

    public function label(): string
    {
        return match ($this) {
            self::InputDistributionShift => 'Input distribution shift',
            self::EvalScoreDecay => 'Eval score decay',
            self::ThumbsDownRate => 'Thumbs-down rate',
            self::LatencyCostSpike => 'Latency / cost spike',
        };
    }
}
