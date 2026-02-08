<?php

namespace App\Domain\Experiment\Enums;

enum StageType: string
{
    case Scoring = 'scoring';
    case Planning = 'planning';
    case Building = 'building';
    case Executing = 'executing';
    case CollectingMetrics = 'collecting_metrics';
    case Evaluating = 'evaluating';
}
