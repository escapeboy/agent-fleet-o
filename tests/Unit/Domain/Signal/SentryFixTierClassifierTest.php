<?php

namespace Tests\Unit\Domain\Signal;

use App\Domain\Signal\Enums\FixTier;
use App\Domain\Signal\Services\SentryFixTierClassifier;
use Tests\TestCase;

class SentryFixTierClassifierTest extends TestCase
{
    private SentryFixTierClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        // Explicit thresholds keep the unit test independent of config.
        $this->classifier = new SentryFixTierClassifier(t1MaxDiffLines: 40, t1MaxFiles: 3);
    }

    /** @return list<string> */
    private function parentFiles(int $count): array
    {
        return array_map(
            static fn (int $i): string => "app/Livewire/Dashboard/Widget{$i}.php",
            range(1, $count),
        );
    }

    public function test_tc1_base_submodule_file_is_t4(): void
    {
        $tier = $this->classifier->classify(['base/app/Domain/Tool/Models/Tool.php'], 12);
        $this->assertSame(FixTier::T4, $tier);
    }

    public function test_tc2_migration_file_is_t4(): void
    {
        $tier = $this->classifier->classify(['database/migrations/2026_05_15_000000_add_col.php'], 10);
        $this->assertSame(FixTier::T4, $tier);
    }

    public function test_tc3_auth_path_is_t4(): void
    {
        $tier = $this->classifier->classify(['app/Http/Middleware/Authenticate.php'], 8);
        $this->assertSame(FixTier::T4, $tier);
    }

    public function test_tc4_core_domain_is_t4(): void
    {
        $tier = $this->classifier->classify(['app/Domain/Experiment/Actions/CreateExperiment.php'], 15);
        $this->assertSame(FixTier::T4, $tier);
    }

    public function test_tc5_large_fix_is_t3(): void
    {
        $tier = $this->classifier->classify($this->parentFiles(10), 200);
        $this->assertSame(FixTier::T3, $tier);
    }

    public function test_tc6_moderate_fix_is_t2(): void
    {
        $tier = $this->classifier->classify($this->parentFiles(4), 70);
        $this->assertSame(FixTier::T2, $tier);
    }

    public function test_tc7_trivial_parent_fix_is_t1(): void
    {
        $tier = $this->classifier->classify(
            ['resources/views/dashboard/stats.blade.php', 'app/Livewire/Dashboard/StatsPanel.php'],
            20,
        );
        $this->assertSame(FixTier::T1, $tier);
    }

    public function test_tc8_empty_input_fails_safe_to_t4(): void
    {
        $this->assertSame(FixTier::T4, $this->classifier->classify([], 0));
        $this->assertSame(FixTier::T4, $this->classifier->classify([], 25));
        $this->assertSame(FixTier::T4, $this->classifier->classify(['app/Livewire/Foo.php'], 0));
    }

    public function test_tc9_exact_t1_boundary_is_t1(): void
    {
        $tier = $this->classifier->classify($this->parentFiles(3), 40);
        $this->assertSame(FixTier::T1, $tier);
    }

    public function test_tc10_one_line_over_t1_boundary_is_t2(): void
    {
        $tier = $this->classifier->classify($this->parentFiles(3), 41);
        $this->assertSame(FixTier::T2, $tier);
    }
}
