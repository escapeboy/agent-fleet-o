<?php

namespace App\Domain\Signal\Enums;

/**
 * Outcome of triaging a single Sentry issue in the watchdog batch.
 */
enum SentryTriageOutcome: string
{
    /** Signal was already delegated/closed — not re-triaged. */
    case Skipped = 'skipped';

    /** Investigated; no autonomous fix delegated (phase 0, or phase 1 below threshold / base-only). */
    case InvestigateOnly = 'investigate_only';

    /** Phase 1 — delegated to a fixing agent which will open a PR. */
    case Delegated = 'delegated';

    /** Triage could not run (e.g. unresolvable team or actor). */
    case Failed = 'failed';
}
