<?php

namespace App\Domain\Experiment\DTOs;

use App\Domain\GitRepository\DTOs\WarmBuildChangeset;

/**
 * The result of one best-of-N warm-build candidate run (Shepherd borrow #1):
 * its worktree, what it changed, and the deterministic signals the scorer ranks
 * on. A failed run is recorded (not thrown away) so one bad candidate never
 * sinks the whole build.
 */
final readonly class WarmBuildCandidate
{
    public const VERIFY_FAIL = 0;

    public const VERIFY_UNKNOWN = 1;   // no verify command configured

    public const VERIFY_PASS = 2;

    public function __construct(
        public int $index,
        public string $runId,
        public ?string $worktree,
        public ?WarmBuildChangeset $changeset,
        public bool $withinRoots,
        public int $verify = self::VERIFY_UNKNOWN,
        public bool $failed = false,
        public ?string $reason = null,
    ) {}

    public function hasChanges(): bool
    {
        return ! $this->failed && $this->changeset !== null && $this->changeset->hasChanges();
    }

    public function branch(): string
    {
        return 'fleetq/fix-'.substr($this->runId, 0, 8);
    }
}
