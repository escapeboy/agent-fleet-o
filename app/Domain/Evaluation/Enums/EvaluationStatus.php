<?php

namespace App\Domain\Evaluation\Enums;

enum EvaluationStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
