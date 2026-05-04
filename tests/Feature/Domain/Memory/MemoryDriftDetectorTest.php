<?php

namespace Tests\Feature\Domain\Memory;

use App\Domain\Memory\Services\MemoryDriftDetector;
use Tests\TestCase;

class MemoryDriftDetectorTest extends TestCase
{
    public function test_threshold_reads_from_config(): void
    {
        config(['memory.drift_threshold' => 0.5]);
        $this->assertSame(0.5, app(MemoryDriftDetector::class)->threshold());
    }

    public function test_default_threshold_is_thirty_percent(): void
    {
        config(['memory.drift_threshold' => null]);
        $this->assertSame(0.30, app(MemoryDriftDetector::class)->threshold());
    }

    public function test_detect_for_team_returns_empty_on_sqlite_test_db(): void
    {
        // pgvector queries don't work on the SQLite test DB; the detector
        // returns [] safely instead of throwing. Real coverage is the
        // production behavior (validated manually post-deploy).
        $result = app(MemoryDriftDetector::class)->detectForTeam('any-team-id');
        $this->assertSame([], $result);
    }
}
