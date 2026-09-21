<?php

namespace Tests\Unit\Domain\Decision;

use App\Domain\Decision\Services\MetricsCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MetricsCalculatorTest extends TestCase
{
    /**
     * Ten rows, hand-checked. Predicted probability is deliberately spread so
     * every ECE bin below is non-empty or empty on purpose.
     *
     * @return list<array<string, mixed>>
     */
    private function fixture(): array
    {
        return [
            ['correct' => true,  'confidence' => 0.95, 'predicted' => 'a', 'gold' => 'a', 'predicted_probability' => 0.95, 'latency_ms' => 100],
            ['correct' => true,  'confidence' => 0.93, 'predicted' => 'a', 'gold' => 'a', 'predicted_probability' => 0.93, 'latency_ms' => 120],
            ['correct' => true,  'confidence' => 0.92, 'predicted' => 'a', 'gold' => 'a', 'predicted_probability' => 0.92, 'latency_ms' => 130],
            ['correct' => false, 'confidence' => 0.91, 'predicted' => 'a', 'gold' => 'b', 'predicted_probability' => 0.91, 'latency_ms' => 140],
            ['correct' => true,  'confidence' => 0.75, 'predicted' => 'b', 'gold' => 'b', 'predicted_probability' => 0.75, 'latency_ms' => 150],
            ['correct' => true,  'confidence' => 0.72, 'predicted' => 'b', 'gold' => 'b', 'predicted_probability' => 0.72, 'latency_ms' => 160],
            ['correct' => false, 'confidence' => 0.71, 'predicted' => 'b', 'gold' => 'a', 'predicted_probability' => 0.71, 'latency_ms' => 170],
            ['correct' => false, 'confidence' => 0.70, 'predicted' => 'b', 'gold' => 'c', 'predicted_probability' => 0.70, 'latency_ms' => 180],
            ['correct' => true,  'confidence' => 0.45, 'predicted' => 'c', 'gold' => 'c', 'predicted_probability' => 0.45, 'latency_ms' => 900],
            ['correct' => false, 'confidence' => 0.42, 'predicted' => 'c', 'gold' => 'a', 'predicted_probability' => 0.42, 'latency_ms' => 950],
        ];
    }

    #[Test]
    public function accuracy_counts_correct_rows(): void
    {
        $this->assertSame(0.6, MetricsCalculator::accuracy($this->fixture()));
        $this->assertSame(0.0, MetricsCalculator::accuracy([]));
    }

    #[Test]
    public function macro_f1_averages_per_class_and_is_not_the_accuracy(): void
    {
        // class a: tp=3 fp=1 fn=2 -> P=0.75 R=0.6   F1=0.666666...
        // class b: tp=2 fp=2 fn=1 -> P=0.5  R=0.666 F1=0.571428...
        // class c: tp=1 fp=1 fn=1 -> P=0.5  R=0.5   F1=0.5
        $expected = (2 / 3 + 4 / 7 + 0.5) / 3;

        $this->assertEqualsWithDelta($expected, MetricsCalculator::macroF1($this->fixture()), 1e-9);
    }

    #[Test]
    public function ece_uses_ten_equal_width_bins(): void
    {
        $calibration = MetricsCalculator::calibration($this->fixture());

        $this->assertCount(10, $calibration['bins']);
        $this->assertSame(10, $calibration['scored']);

        // bin 0.9-1.0: 4 rows, mean p = 0.9275, accuracy 0.75  -> gap 0.1775
        // bin 0.7-0.8: 4 rows, mean p = 0.72,   accuracy 0.50  -> gap 0.22
        // bin 0.4-0.5: 2 rows, mean p = 0.435,  accuracy 0.50  -> gap 0.065
        $populated = array_values(array_filter($calibration['bins'], static fn (array $b): bool => $b['count'] > 0));
        $this->assertCount(3, $populated);

        $expected = (4 / 10) * 0.1775 + (4 / 10) * 0.22 + (2 / 10) * 0.065;
        $this->assertEqualsWithDelta($expected, $calibration['ece'], 1e-9);
    }

    #[Test]
    public function ece_is_skipped_when_no_row_carries_a_probability(): void
    {
        $rows = array_map(static function (array $row): array {
            $row['predicted_probability'] = null;

            return $row;
        }, $this->fixture());

        $calibration = MetricsCalculator::calibration($rows);

        $this->assertSame(0, $calibration['scored']);
        $this->assertSame(0.0, $calibration['ece']);
    }

    #[Test]
    public function coverage_finds_the_widest_threshold_that_holds_the_target_precision(): void
    {
        // At threshold 0.92 the three answered rows are all correct: precision 1.0,
        // coverage 3/10. Dropping to 0.91 adds a wrong one (0.75) so 0.95 is lost.
        $result = MetricsCalculator::coverageAtPrecision($this->fixture(), 0.95);

        $this->assertNotNull($result);
        $this->assertSame(0.3, $result['coverage']);
        $this->assertSame(0.92, $result['threshold']);
        $this->assertSame(1.0, $result['precision']);
    }

    #[Test]
    public function coverage_reports_unreachable_rather_than_pretending(): void
    {
        $rows = array_map(static function (array $row): array {
            $row['correct'] = false;

            return $row;
        }, $this->fixture());

        $this->assertNull(MetricsCalculator::coverageAtPrecision($rows, 0.99));
    }

    #[Test]
    public function coverage_is_null_when_nothing_carries_a_confidence(): void
    {
        $rows = array_map(static function (array $row): array {
            $row['confidence'] = null;

            return $row;
        }, $this->fixture());

        $this->assertNull(MetricsCalculator::coverageAtPrecision($rows, 0.95));
    }

    #[Test]
    public function percentiles_interpolate(): void
    {
        $latencies = array_column($this->fixture(), 'latency_ms');

        $this->assertSame(155.0, MetricsCalculator::percentile($latencies, 50));
        $this->assertEqualsWithDelta(927.5, MetricsCalculator::percentile($latencies, 95), 1e-9);
        $this->assertSame(0.0, MetricsCalculator::percentile([], 50));
    }

    #[Test]
    public function determinism_reports_the_worst_option_spread_across_repeats(): void
    {
        $spread = MetricsCalculator::determinism([
            'case-1|domain' => [
                ['a' => 0.90, 'b' => 0.10],
                ['a' => 0.90, 'b' => 0.10],
            ],
            'case-2|domain' => [
                ['a' => 0.60, 'b' => 0.40],
                ['a' => 0.80, 'b' => 0.20],
            ],
        ]);

        // Sample stddev of [0.6, 0.8] is 0.1414...; case-1 contributes nothing.
        $this->assertEqualsWithDelta(sqrt(0.02), $spread, 1e-9);
    }

    #[Test]
    public function determinism_ignores_a_single_pass(): void
    {
        $this->assertSame(0.0, MetricsCalculator::determinism([
            'case-1|domain' => [['a' => 0.9, 'b' => 0.1]],
        ]));
    }
}
