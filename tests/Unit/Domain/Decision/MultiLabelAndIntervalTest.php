<?php

namespace Tests\Unit\Domain\Decision;

use App\Domain\Decision\Services\MetricsCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MultiLabelAndIntervalTest extends TestCase
{
    #[Test]
    public function wilson_brackets_the_observed_rate(): void
    {
        $interval = MetricsCalculator::wilsonInterval(8, 10);

        $this->assertLessThan(0.8, $interval['low']);
        $this->assertGreaterThan(0.8, $interval['high']);
        // Hand-checked against the closed form for p=0.8, n=10, z=1.96.
        $this->assertEqualsWithDelta(0.4901, $interval['low'], 1e-3);
        $this->assertEqualsWithDelta(0.9433, $interval['high'], 1e-3);
    }

    #[Test]
    public function wilson_stays_inside_zero_and_one_at_the_extremes(): void
    {
        $perfect = MetricsCalculator::wilsonInterval(5, 5);
        $this->assertSame(1.0, $perfect['high']);
        $this->assertGreaterThan(0.0, $perfect['low']);
        $this->assertLessThan(1.0, $perfect['low']);

        $none = MetricsCalculator::wilsonInterval(0, 5);
        $this->assertSame(0.0, $none['low']);
        $this->assertGreaterThan(0.0, $none['high']);

        // A group with no rows has no interval rather than a divide by zero.
        $this->assertSame(['low' => 0.0, 'high' => 0.0], MetricsCalculator::wilsonInterval(0, 0));
    }

    /**
     * Three cases over four labels, hand-checked at two thresholds.
     *
     * @return list<array{scores: array<string, float>, gold: list<string>}>
     */
    private function cases(): array
    {
        return [
            ['scores' => ['a' => 0.9, 'b' => 0.4, 'c' => 0.1, 'd' => 0.05], 'gold' => ['a']],
            ['scores' => ['a' => 0.3, 'b' => 0.8, 'c' => 0.6, 'd' => 0.02], 'gold' => ['b', 'c']],
            ['scores' => ['a' => 0.2, 'b' => 0.2, 'c' => 0.2, 'd' => 0.7], 'gold' => ['d']],
        ];
    }

    #[Test]
    public function the_sweep_trades_labels_kept_against_recall(): void
    {
        $sweep = MetricsCalculator::multiLabelSweep($this->cases(), [0.5, 0.9]);

        $this->assertCount(2, $sweep);

        // t=0.5: case 1 keeps {a} (recall 1, precision 1, 1 label);
        //        case 2 keeps {b,c} (recall 1, precision 1, 2 labels);
        //        case 3 keeps {d} (recall 1, precision 1, 1 label).
        $this->assertSame(0.5, $sweep[0]['threshold']);
        $this->assertSame(3, $sweep[0]['cases']);
        $this->assertEqualsWithDelta(1.0, $sweep[0]['recall'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $sweep[0]['precision'], 1e-9);
        $this->assertEqualsWithDelta(4 / 3, $sweep[0]['kept'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $sweep[0]['perfect_recall'], 1e-9);

        // t=0.9: case 1 keeps {a} (recall 1); case 2 keeps {} (recall 0,
        // precision 0); case 3 keeps {} (recall 0, precision 0).
        $this->assertEqualsWithDelta(1 / 3, $sweep[1]['recall'], 1e-9);
        $this->assertEqualsWithDelta(1 / 3, $sweep[1]['precision'], 1e-9);
        $this->assertEqualsWithDelta(1 / 3, $sweep[1]['kept'], 1e-9);
        $this->assertEqualsWithDelta(1 / 3, $sweep[1]['perfect_recall'], 1e-9);
    }

    #[Test]
    public function a_case_with_no_true_labels_is_skipped_rather_than_scored_as_perfect(): void
    {
        $cases = $this->cases();
        $cases[] = ['scores' => ['a' => 0.1, 'b' => 0.1, 'c' => 0.1, 'd' => 0.1], 'gold' => []];

        $sweep = MetricsCalculator::multiLabelSweep($cases, [0.5]);

        $this->assertSame(3, $sweep[0]['cases']);
        $this->assertEqualsWithDelta(1.0, $sweep[0]['recall'], 1e-9);
    }

    #[Test]
    public function an_empty_prefilter_scores_zero_precision_not_a_skip(): void
    {
        $sweep = MetricsCalculator::multiLabelSweep(
            [['scores' => ['a' => 0.1, 'b' => 0.1], 'gold' => ['a']]],
            [0.9],
        );

        $this->assertSame(0.0, $sweep[0]['recall']);
        $this->assertSame(0.0, $sweep[0]['precision']);
        $this->assertSame(0.0, $sweep[0]['kept']);
    }

    #[Test]
    public function determinism_stats_report_spread_and_argmax_flips(): void
    {
        $stats = MetricsCalculator::determinismStats([
            // Stable: same winner, no spread.
            'stable' => [['a' => 0.9, 'b' => 0.1], ['a' => 0.9, 'b' => 0.1]],
            // Moves but keeps the same winner.
            'wobbly' => [['a' => 0.6, 'b' => 0.4], ['a' => 0.8, 'b' => 0.2]],
            // Winner changes between repeats.
            'flipped' => [['a' => 0.6, 'b' => 0.4], ['a' => 0.4, 'b' => 0.6]],
        ]);

        $this->assertSame(3, $stats['repeated_cases']);
        $this->assertEqualsWithDelta(sqrt(0.02), $stats['max_stddev'], 1e-9);
        $this->assertGreaterThan(0.0, $stats['mean_stddev']);
        $this->assertLessThan($stats['max_stddev'], $stats['mean_stddev']);
        $this->assertEqualsWithDelta(1 / 3, $stats['argmax_flip_rate'], 1e-9);
    }

    #[Test]
    public function determinism_stats_are_empty_without_repeats(): void
    {
        $stats = MetricsCalculator::determinismStats(['only-one' => [['a' => 1.0]]]);

        $this->assertSame(0, $stats['repeated_cases']);
        $this->assertSame(0.0, $stats['argmax_flip_rate']);
    }
}
