<?php

namespace App\Domain\Evaluation\Enums;

/**
 * Lifecycle status of an evaluation case.
 *
 * - Active:   gates the run as usual (score below threshold fails the run).
 * - Deferred: scored but non-gating. A deferred case that scores at/above the
 *   threshold is surfaced as a "silent win" (the article's deferred-eval pattern):
 *   an unrelated change accidentally fixed the failure mode. Promotion to Active
 *   is a manual decision.
 */
enum EvaluationCaseStatus: string
{
    case Active = 'active';
    case Deferred = 'deferred';
}
