<?php

namespace App\Domain\Signal\Services;

use App\Domain\Signal\Enums\FixTier;

/**
 * Deterministic risk classifier for an agent-produced fix.
 *
 * Pure function: given the files a fix touches and its size, returns a
 * FixTier. Rules are ordered and the first match wins. Anything that
 * cannot be assessed fails safe to T4 (human-only). See
 * claudedocs/architecture-sentry-watchdog.md §4.
 */
class SentryFixTierClassifier
{
    /** Above this changed-line count a parent-repo fix is at least T3. */
    private const T3_MAX_DIFF_LINES = 150;

    /** Above this file count a parent-repo fix is at least T3. */
    private const T3_MAX_FILES = 8;

    /**
     * Path substrings (matched case-insensitively) that force T4 regardless
     * of fix size: authentication, billing, and the core domains that run
     * the watchdog itself. NFR-11 — the agent must never autonomously
     * change its own control machinery. Over-matching here is safe: T4 is
     * the conservative direction.
     *
     * @var list<string>
     */
    private const SENSITIVE_PATH_FRAGMENTS = [
        'auth',
        'fortify',
        'sanctum',
        'passport',
        'billing',
        'cashier',
        'domain/experiment',
        'domain/crew',
        'domain/signal',
        'domain/project',
        'domain/budget',
        'domain/credential',
        'infrastructure/ai',
    ];

    private readonly int $t1MaxDiffLines;

    private readonly int $t1MaxFiles;

    public function __construct(?int $t1MaxDiffLines = null, ?int $t1MaxFiles = null)
    {
        $this->t1MaxDiffLines = $t1MaxDiffLines ?? (int) config('sentry_watchdog.t1_max_diff_lines', 40);
        $this->t1MaxFiles = $t1MaxFiles ?? (int) config('sentry_watchdog.t1_max_files', 3);
    }

    /**
     * @param  list<string>  $files  Repo-relative paths the fix touches (e.g. "app/Foo.php", "base/app/Bar.php").
     * @param  int  $diffLines  Total changed lines — estimated at triage, exact after a PR is opened.
     */
    public function classify(array $files, int $diffLines): FixTier
    {
        // Rule 7 (fail-safe): nothing concrete to assess.
        if ($files === [] || $diffLines <= 0) {
            return FixTier::T4;
        }

        $normalized = array_map(
            static fn (string $path): string => strtolower(trim($path)),
            $files,
        );

        // Rule 1 (was: base/ submodule → T4) — superseded by submodule-aware
        // routing in TriageSentryIssueAction (phase 1 enablement sprint).
        // Pure-base suspect_files now delegate against escapeboy/agent-fleet-o
        // and are tier-classified the same as parent-repo paths.

        // Rule 2: migrations / schema changes.
        foreach ($normalized as $path) {
            if (str_contains($path, 'database/migrations')) {
                return FixTier::T4;
            }
        }

        // Rule 3: auth / billing / core domains.
        foreach ($normalized as $path) {
            foreach (self::SENSITIVE_PATH_FRAGMENTS as $fragment) {
                if (str_contains($path, $fragment)) {
                    return FixTier::T4;
                }
            }
        }

        $fileCount = count($files);

        // Rule 4: large fix.
        if ($diffLines > self::T3_MAX_DIFF_LINES || $fileCount > self::T3_MAX_FILES) {
            return FixTier::T3;
        }

        // Rule 5: moderate fix.
        if ($diffLines > $this->t1MaxDiffLines || $fileCount > $this->t1MaxFiles) {
            return FixTier::T2;
        }

        // Rule 6: trivial, parent-only, non-sensitive.
        return FixTier::T1;
    }
}
