<?php

namespace App\Console\Commands;

use App\Domain\Decision\Models\DecisionEval;
use App\Domain\Decision\Services\DatasetLoader;
use App\Domain\Decision\Services\MetricsCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class JevReportCommand extends Command
{
    protected $signature = 'jev:report
        {run_id? : Run to report on; defaults to the most recent run}
        {--dataset-path= : Dataset file, needed only for the variant analysis (default: search ~/jev-eval/datasets)}';

    protected $description = 'Summarise a decision-model eval run: accuracy, calibration, coverage, latency, cost and determinism.';

    /** @var array<float> */
    private const PRECISION_TARGETS = [0.95, 0.97, 0.99];

    public function handle(DatasetLoader $loader): int
    {
        $runId = (string) ($this->argument('run_id') ?? DecisionEval::query()->latest('created_at')->value('run_id'));

        if ($runId === '') {
            $this->error('No eval runs recorded yet.');

            return self::FAILURE;
        }

        $rows = DecisionEval::query()->where('run_id', $runId)->get();

        if ($rows->isEmpty()) {
            $this->error("No rows for run [{$runId}].");

            return self::FAILURE;
        }

        $this->info("Run {$runId} — ".$rows->count().' answer rows');
        $this->newLine();

        $tokensPerDecision = $this->tokensPerDecision($rows);

        foreach ($rows->groupBy(fn (DecisionEval $r): string => $r->driver.'|'.$r->dataset.'|'.$r->question_id) as $key => $group) {
            [$driver, $dataset, $questionId] = explode('|', (string) $key);
            $this->reportGroup($driver, $dataset, $questionId, $group, $tokensPerDecision);
        }

        $this->variantAnalysis($loader, $rows);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, DecisionEval>  $group
     * @param  array<string, float>  $tokensPerDecision
     */
    private function reportGroup(string $driver, string $dataset, string $questionId, Collection $group, array $tokensPerDecision): void
    {
        $scoring = $group->map(fn (DecisionEval $r): array => $this->toScoringRow($r))->all();
        $latencies = $group->pluck('latency_ms')->map(static fn ($v): int => (int) $v)->all();

        $calibration = MetricsCalculator::calibration($scoring);
        $pricePerMtok = (float) config("decision.pricing_usd_per_mtok.{$driver}", 0.0);
        $attributedTokens = $group->sum(fn (DecisionEval $r): float => $tokensPerDecision[$r->id] ?? 0.0);
        $costPer1k = $group->count() > 0
            ? ($attributedTokens / $group->count()) * 1000 * $pricePerMtok / 1_000_000
            : 0.0;

        $this->line("<options=bold>{$driver}</> · {$dataset} · question <options=bold>{$questionId}</>  (model: ".($group->first()->model ?? '?').')');

        $this->table(['metric', 'value'], [
            ['decisions', (string) $group->count()],
            ['accuracy', $this->pct(MetricsCalculator::accuracy($scoring))],
            ['macro-F1', number_format(MetricsCalculator::macroF1($scoring), 4)],
            ['ECE (10 bins)', $calibration['scored'] > 0 ? number_format($calibration['ece'], 4) : 'n/a (no probabilities)'],
            ['latency p50', $this->ms(MetricsCalculator::percentile($latencies, 50))],
            ['latency p95', $this->ms(MetricsCalculator::percentile($latencies, 95))],
            ['input tokens / decision', number_format($group->count() > 0 ? $attributedTokens / $group->count() : 0, 1)],
            ['cost / 1k decisions', '$'.number_format($costPer1k, 4)],
            ['determinism (max σ of p)', $this->determinismLabel($group)],
        ]);

        if ($calibration['scored'] > 0) {
            $this->line('  reliability:');
            $bins = [];

            foreach ($calibration['bins'] as $bin) {
                if ($bin['count'] === 0) {
                    continue;
                }

                $bins[] = [
                    sprintf('%.1f–%.1f', $bin['lower'], $bin['upper']),
                    (string) $bin['count'],
                    number_format($bin['confidence'], 3),
                    number_format($bin['accuracy'], 3),
                    number_format($bin['gap'], 3),
                ];
            }

            $this->table(['bin', 'n', 'mean p', 'accuracy', 'gap'], $bins);
        }

        $coverage = [];

        foreach (self::PRECISION_TARGETS as $target) {
            $result = MetricsCalculator::coverageAtPrecision($scoring, $target);

            $coverage[] = $result === null
                ? [$this->pct($target), 'unreachable', '—', '—']
                : [$this->pct($target), $this->pct($result['coverage']), number_format($result['threshold'], 3), $this->pct($result['precision'])];
        }

        $this->line('  coverage at target precision:');
        $this->table(['target precision', 'coverage', 'confidence ≥', 'actual precision'], $coverage);
        $this->newLine();
    }

    /**
     * Cases answer several questions in one request, so the request's input
     * tokens belong to all of them together. Splitting the cost evenly across a
     * request's questions is what makes "cost per 1k decisions" comparable
     * between a dataset with one question and one with two.
     *
     * @param  Collection<int, DecisionEval>  $rows
     * @return array<string, float> eval row id => tokens attributed to it
     */
    private function tokensPerDecision(Collection $rows): array
    {
        $attributed = [];

        foreach ($rows->groupBy(fn (DecisionEval $r): string => $r->driver.'|'.$r->case_id.'|'.$r->repeat_index) as $group) {
            $perQuestion = $group->count() > 0 ? (float) ($group->first()->input_tokens ?? 0) / $group->count() : 0.0;

            foreach ($group as $row) {
                $attributed[$row->id] = $perQuestion;
            }
        }

        return $attributed;
    }

    /**
     * @return array<string, mixed>
     */
    private function toScoringRow(DecisionEval $row): array
    {
        $answer = $row->answer ?? [];
        $predicted = (string) ($answer['choice'] ?? $answer['score'] ?? $answer['noul'] ?? '');
        $probabilities = $row->probabilities;

        $predictedProbability = is_array($probabilities) && array_key_exists($predicted, $probabilities)
            ? (float) $probabilities[$predicted]
            : (is_array($probabilities) && $probabilities !== [] ? max(array_map('floatval', $probabilities)) : $row->confidence);

        return [
            'correct' => (bool) $row->correct,
            'confidence' => $row->confidence ?? $predictedProbability,
            'predicted' => $predicted,
            'gold' => $row->goldLabel(),
            'predicted_probability' => $predictedProbability,
            'latency_ms' => (int) $row->latency_ms,
        ];
    }

    /**
     * @param  Collection<int, DecisionEval>  $group
     */
    private function determinismLabel(Collection $group): string
    {
        $byCase = [];

        foreach ($group as $row) {
            if (! is_array($row->probabilities)) {
                continue;
            }

            $byCase[$row->case_id.'|'.$row->question_id][] = array_map('floatval', $row->probabilities);
        }

        $repeated = array_filter($byCase, static fn (array $repeats): bool => count($repeats) > 1);

        if ($repeated === []) {
            return 'n/a (single pass)';
        }

        return number_format(MetricsCalculator::determinism($repeated), 5);
    }

    /**
     * Flip rate and P(gold) drift for perturbation datasets: every variant is
     * compared against its own "clean" sibling, matched through meta.base_id.
     *
     * @param  Collection<int, DecisionEval>  $rows
     */
    private function variantAnalysis(DatasetLoader $loader, Collection $rows): void
    {
        $datasetName = (string) ($rows->first()->dataset ?? '');
        $path = $this->resolveDatasetPath($datasetName);

        if ($path === null) {
            return;
        }

        $meta = [];

        foreach ($loader->load($path) as $case) {
            if ($case->variant() !== null && $case->baseId() !== null) {
                $meta[$case->id] = ['variant' => $case->variant(), 'base_id' => $case->baseId()];
            }
        }

        if ($meta === []) {
            return;
        }

        $this->line('<options=bold>Variant robustness</> (vs the clean sibling, matched on meta.base_id)');

        foreach ($rows->groupBy(fn (DecisionEval $r): string => $r->driver.'|'.$r->question_id) as $key => $group) {
            [$driver, $questionId] = explode('|', (string) $key);

            // base_id + question => the clean row, and the variant rows to compare to it.
            $clean = [];
            $variants = [];

            foreach ($group as $row) {
                $info = $meta[$row->case_id] ?? null;

                if ($info === null) {
                    continue;
                }

                if ($info['variant'] === 'clean') {
                    $clean[$info['base_id']] = $row;
                } else {
                    $variants[$info['variant']][] = [$info['base_id'], $row];
                }
            }

            $table = [];

            foreach ($variants as $variant => $pairs) {
                $flips = 0;
                $compared = 0;
                $deltas = [];

                foreach ($pairs as [$baseId, $row]) {
                    $cleanRow = $clean[$baseId] ?? null;

                    if ($cleanRow === null) {
                        continue;
                    }

                    $compared++;

                    if (($row->answer['choice'] ?? null) !== ($cleanRow->answer['choice'] ?? null)) {
                        $flips++;
                    }

                    $goldClass = $row->goldLabel();
                    $pVariant = is_array($row->probabilities) ? (float) ($row->probabilities[$goldClass] ?? 0) : null;
                    $pClean = is_array($cleanRow->probabilities) ? (float) ($cleanRow->probabilities[$goldClass] ?? 0) : null;

                    if ($pVariant !== null && $pClean !== null) {
                        $deltas[] = $pVariant - $pClean;
                    }
                }

                if ($compared === 0) {
                    continue;
                }

                $table[] = [
                    $driver,
                    $questionId,
                    $variant,
                    (string) $compared,
                    $this->pct($flips / $compared),
                    $deltas === [] ? 'n/a' : number_format(array_sum($deltas) / count($deltas), 4),
                ];
            }

            if ($table !== []) {
                $this->table(['driver', 'question', 'variant', 'pairs', 'flip rate', 'mean Δ P(gold)'], $table);
            }
        }
    }

    private function resolveDatasetPath(string $datasetName): ?string
    {
        $explicit = $this->option('dataset-path');

        if (is_string($explicit) && $explicit !== '') {
            return is_file($explicit) ? $explicit : null;
        }

        if ($datasetName === '') {
            return null;
        }

        $home = (string) (getenv('HOME') ?: '');

        if ($home === '') {
            return null;
        }

        $matches = glob($home.'/jev-eval/datasets/*/'.$datasetName) ?: [];

        return $matches[0] ?? null;
    }

    private function pct(float $value): string
    {
        return number_format($value * 100, 2).'%';
    }

    private function ms(float $value): string
    {
        return number_format($value, 0).' ms';
    }
}
