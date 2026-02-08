<?php

namespace App\Domain\Experiment\Enums;

enum ExperimentTrack: string
{
    case Growth = 'growth';
    case Retention = 'retention';
    case Revenue = 'revenue';
    case Engagement = 'engagement';
}
