<?php

namespace App\Domain\Decision\Services;

/**
 * Pure scoring maths over already-recorded eval rows. No database, no HTTP —
 * every function here takes plain arrays so the numbers can be pinned to a
 * fixture in a test instead of being trusted because the report printed them.
 *
 * A "row" is an associative array with at least:
 *   correct (bool), confidence (?float), predicted (string), gold (string),
 *   predicted_probability (?float), latency_ms (int)
 */
final class MetricsCalculator
{
    public const ECE_BINS = 10;

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public static function accuracy(array $rows): float
    {
        if ($rows === []) {
            return 0.0;
        }

        $correct = count(array_filter($rows, static fn (array $r): bool => (bool) $r['correct']));

        return $correct / count($rows);
    }

    /**
     * Macro-F1 over every class that appears as either a gold label or a
     * prediction. Macro, not micro: the routing dataset is dominated by two
     * domains, and a micro average would hide a driver that never picks the
     * rare ones.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function macroF1(array $rows): float
    {
        if ($rows === []) {
            return 0.0;
        }

        $classes = [];

        foreach ($rows as $row) {
            $classes[(string) $row['gold']] = true;
            $classes[(string) $row['predicted']] = true;
        }

        $scores = [];

        foreach (array_keys($classes) as $class) {
            $tp = $fp = $fn = 0;

            foreach ($rows as $row) {
                $predicted = (string) $row['predicted'] === $class;
                $actual = (string) $row['gold'] === $class;

                if ($predicted && $actual) {
                    $tp++;
                } elseif ($predicted) {
                    $fp++;
                } elseif ($actual) {
                    $fn++;
                }
            }

            $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
            $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
            $scores[] = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;
        }

        return array_sum($scores) / count($scores);
    }

    /**
     * Expected Calibration Error over equal-width bins of the predicted-class
     * probability. Rows without a probability are skipped — a driver that
     * reports none simply has no calibration to measure, and folding them in at
     * 0 would invent a miscalibration that is not there.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{ece: float, bins: list<array{lower: float, upper: float, count: int, confidence: float, accuracy: float, gap: float}>, scored: int}
     */
    public static function calibration(array $rows, int $binCount = self::ECE_BINS): array
    {
        $scored = array_values(array_filter(
            $rows,
            static fn (array $r): bool => is_numeric($r['predicted_probability'] ?? null),
        ));

        $bins = [];

        for ($i = 0; $i < $binCount; $i++) {
            $bins[$i] = ['lower' => $i / $binCount, 'upper' => ($i + 1) / $binCount, 'count' => 0, 'confidence' => 0.0, 'accuracy' => 0.0, 'gap' => 0.0];
        }

        if ($scored === []) {
            return ['ece' => 0.0, 'bins' => array_values($bins), 'scored' => 0];
        }

        // Grouped rather than accumulated so an empty bin simply does not exist,
        // instead of needing a zero-division guard on every read.
        $grouped = [];

        foreach ($scored as $row) {
            $p = (float) $row['predicted_probability'];
            $grouped[min($binCount - 1, max(0, (int) floor($p * $binCount)))][] = $row;
        }

        $total = count($scored);
        $ece = 0.0;

        foreach ($grouped as $index => $group) {
            $n = count($group);
            $confidence = array_sum(array_map(static fn (array $r): float => (float) $r['predicted_probability'], $group)) / $n;
            $accuracy = count(array_filter($group, static fn (array $r): bool => (bool) $r['correct'])) / $n;
            $gap = abs($accuracy - $confidence);

            $bins[$index]['count'] = $n;
            $bins[$index]['confidence'] = $confidence;
            $bins[$index]['accuracy'] = $accuracy;
            $bins[$index]['gap'] = $gap;

            $ece += ($n / $total) * $gap;
        }

        return ['ece' => $ece, 'bins' => array_values($bins), 'scored' => $total];
    }

    /**
     * The largest share of cases a driver can answer while staying at or above
     * `$targetPrecision` on the ones it answers, found by sweeping the
     * confidence threshold across every observed value.
     *
     * Returns null when no threshold reaches the target — which is the useful
     * answer, not a failure: it means the driver cannot be gated into that
     * precision on this dataset at all.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{coverage: float, threshold: float, precision: float}|null
     */
    public static function coverageAtPrecision(array $rows, float $targetPrecision): ?array
    {
        $scored = array_values(array_filter($rows, static fn (array $r): bool => is_numeric($r['confidence'] ?? null)));

        if ($scored === []) {
            return null;
        }

        $total = count($scored);
        $thresholds = array_values(array_unique(array_map(static fn (array $r): float => (float) $r['confidence'], $scored)));
        sort($thresholds);

        $best = null;

        foreach ($thresholds as $threshold) {
            $answered = array_values(array_filter($scored, static fn (array $r): bool => (float) $r['confidence'] >= $threshold));

            if ($answered === []) {
                continue;
            }

            $precision = self::accuracy($answered);

            if ($precision + 1e-9 < $targetPrecision) {
                continue;
            }

            $coverage = count($answered) / $total;

            if ($best === null || $coverage > $best['coverage']) {
                $best = ['coverage' => $coverage, 'threshold' => $threshold, 'precision' => $precision];
            }
        }

        return $best;
    }

    /**
     * @param  list<int|float>  $values
     */
    public static function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $rank = ($percentile / 100) * (count($values) - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);

        if ($low === $high) {
            return (float) $values[$low];
        }

        return (float) $values[$low] + ($rank - $low) * ((float) $values[$high] - (float) $values[$low]);
    }

    /**
     * Worst-case spread of a single option's probability across repeats of the
     * identical request. Max, not mean: one unstable option is enough to make a
     * confidence threshold unreliable, and averaging would bury it.
     *
     * @param  array<string, list<array<string, float>>>  $byCase  case+question key => one probability map per repeat
     */
    public static function determinism(array $byCase): float
    {
        $worst = 0.0;

        foreach ($byCase as $repeats) {
            if (count($repeats) < 2) {
                continue;
            }

            $options = [];

            foreach ($repeats as $map) {
                foreach (array_keys($map) as $option) {
                    $options[$option] = true;
                }
            }

            foreach (array_keys($options) as $option) {
                $series = array_map(static fn (array $map): float => (float) ($map[$option] ?? 0.0), $repeats);
                $worst = max($worst, self::stddev($series));
            }
        }

        return $worst;
    }

    /**
     * @param  list<float>  $values
     */
    public static function stddev(array $values): float
    {
        $n = count($values);

        if ($n < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $n;
        $variance = array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $values)) / ($n - 1);

        return sqrt($variance);
    }
}
