<?php

namespace App\Domain\Evaluation\Enums;

/**
 * Provenance of an evaluation case — where the failure mode was named.
 */
enum EvaluationCaseSource: string
{
    case Manual = 'manual';
    case FailureLesson = 'failure_lesson';
    case TaskValidation = 'task_validation';
    case ThumbsDown = 'thumbs_down';
    case Drift = 'drift';
}
