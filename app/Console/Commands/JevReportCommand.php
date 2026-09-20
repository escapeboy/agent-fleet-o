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
        {run_id?* : Runs to report on; defaults to the most recent run}
        {--dataset-path= : Dataset file; needed for --group-by, --where and the variant analysis (default: search ~/jev-eval/datasets)}
        {--group-by= : Split every table by a dataset meta key, e.g. meta.source}
        {--where= : Keep only cases matching a meta key, e.g. meta.source=assistant_turn (comma-separated values allowed)}
        {--questions= : Comma-separated question ids to report on; others are dropped}
        {--multi-label : Score the run as one multi-label set per case (Noul per label) instead of per question}';

    protected $description = 'Summarise a decision-model eval run: accuracy, calibration, coverage, latency, cost and determinism.';

    /** @var array<float> */
    private const PRECISION_TARGETS = [0.95, 0.97, 0.99];

    /** case id => dataset meta, loaded lazily and only when an option needs it */
    private array $caseMeta = [];

    public function handle(DatasetLoader $loader): int
    {
        $runIds = array_values(array_filter((array) $this->argument('run_id')));

        if ($runIds === []) {
            $latest = DecisionEval::query()->latest('created_at')->value('run_id');

            if (! is_string($latest) || $latest === '') {
                $this->error('No eval runs recorded yet.');

                return self::FAILURE;
            }

            $runIds = [$latest];
        }

        $rows = DecisionEval::query()->whereIn('run_id', $runIds)->get();

        if ($rows->isEmpty()) {
            $this->error('No rows for run(s) ['.implode(', ', $runIds).'].');

            return self::FAILURE;
        }

        $questions = $this->listOption('questions');

        if ($questions !== []) {
            $rows = $rows->filter(fn (DecisionEval $r): bool => in_array($r->question_id, $questions, true))->values();
        }

        $groupBy = $this->metaKeyOption('group-by');
        $where = $this->whereOption();

        if ($groupBy !== null || $where !== null) {
            $this->loadCaseMeta($loader, $rows);
        }

        if ($where !== null) {
            [$key, $values] = $where;
            $rows = $rows->filter(fn (DecisionEval $r): bool => in_array($this->metaValue($r, $key), $values, true))->values();
            $this->line("Filtered to meta.{$key} in [".implode(', ', $values).'].');
        }

        if ($rows->isEmpty()) {
            $this->error('Nothing left to report after the filters.');

            return self::FAILURE;
        }

        $this->info('Run(s) '.implode(', ', $runIds).' — '.$rows->count().' answer rows');
        $this->newLine();

        if ($this->option('multi-label')) {
            $this->multiLabelReport($rows, $groupBy);

            return self::SUCCESS;
        }

        $tokensPerDecision = $this->tokensPerDecision($rows);

        $grouped = $rows->groupBy(function (DecisionEval $r) use ($groupBy): string {
            $suffix = $groupBy === null ? '' : '|'.$this->metaValue($r, $groupBy);

            return $r->driver.'|'.$r->dataset.'|'.$r->question_id.$suffix;
        });

        foreach ($grouped->sortKeys() as $key => $group) {
            $parts = explode('|', (string) $key);
            $this->reportGroup($parts[0], $parts[1], $parts[2], $parts[3] ?? null, $group, $tokensPerDecision);
        }

        $this->variantAnalysis($loader, $rows);

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function listOption(string $name): array
    {
        $raw = $this->option($name);

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * `meta.source` and a bare `source` both mean the same key.
     */
    private function metaKeyOption(string $name): ?string
    {
        $raw = $this->option($name);

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $key = preg_replace('/^meta\./', '', trim($raw));

        return $key === '' ? null : $key;
    }

    /**
     * @return array{0: string, 1: list<string>}|null
     */
    private function whereOption(): ?array
    {
        $raw = $this->option('where');

        if (! is_string($raw) || ! str_contains($raw, '=')) {
            return null;
        }

        [$key, $values] = explode('=', $raw, 2);
        $key = preg_replace('/^meta\./', '', trim($key));

        return [$key, array_values(array_filter(array_map('trim', explode(',', $values))))];
    }

    /**
     * @param  Collection<int, DecisionEval>  $rows
     */
    private function loadCaseMeta(DatasetLoader $loader, Collection $rows): void
    {
        $datasets = $rows->pluck('dataset')->unique();

        if ($datasets->count() > 1) {
            // Case ids are unique per dataset, so one file cannot describe rows
            // from several. Comparing drivers is fine; comparing datasets is not.
            $this->warn('Rows span '.$datasets->count().' datasets ('.$datasets->implode(', ').') — meta grouping needs one dataset at a time.');

            return;
        }

        $path = $this->resolveDatasetPath((string) ($rows->first()->dataset ?? ''));

        if ($path === null) {
            $this->warn('No dataset file found — meta grouping and filtering need one (pass --dataset-path).');

            return;
        }

        foreach ($loader->load($path) as $case) {
            $this->caseMeta[$case->id] = $case->meta;
        }
    }

    private function metaValue(DecisionEval $row, string $key): string
    {
        $value = $this->caseMeta[$row->case_id][$key] ?? null;

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '(none)';
    }

    /**
     * @param  Collection<int, DecisionEval>  $group
     * @param  array<string, float>  $tokensPerDecision
     */
    private function reportGroup(string $driver, string $dataset, string $questionId, ?string $groupValue, Collection $group, array $tokensPerDecision): void
    {
        $scoring = $group->map(fn (DecisionEval $r): array => $this->toScoringRow($r))->all();
        $latencies = $group->pluck('latency_ms')->map(static fn ($v): int => (int) $v)->all();

        $calibration = MetricsCalculator::calibration($scoring);
        $pricePerMtok = (float) config("decision.pricing_usd_per_mtok.{$driver}", 0.0);
        $attributedTokens = $group->sum(fn (DecisionEval $r): float => $tokensPerDecision[$r->id] ?? 0.0);
        $costPer1k = $group->count() > 0
            ? ($attributedTokens / $group->count()) * 1000 * $pricePerMtok / 1_000_000
            : 0.0;

        $suffix = $groupValue === null ? '' : " · <options=bold>{$groupValue}</>";
        $this->line("<options=bold>{$driver}</> · {$dataset} · question <options=bold>{$questionId}</>{$suffix}  (model: ".($group->first()->model ?? '?').')');

        $this->table(['metric', 'value'], [
            ['decisions', (string) $group->count()],
            ['accuracy', $this->accuracyWithInterval($scoring)],
            ['macro-F1', number_format(MetricsCalculator::macroF1($scoring), 4)],
            ['ECE (10 bins)', $calibration['scored'] > 0 ? number_format($calibration['ece'], 4) : 'n/a (no probabilities)'],
            ['latency p50', $this->ms(MetricsCalculator::percentile($latencies, 50))],
            ['latency p95', $this->ms(MetricsCalculator::percentile($latencies, 95))],
            ['input tokens / decision', number_format($group->count() > 0 ? $attributedTokens / $group->count() : 0, 1)],
            ['cost / 1k decisions', '$'.number_format($costPer1k, 4)],
            ['determinism (max σ of p)', $this->determinismLabel($group, 'max_stddev')],
            ['determinism (mean σ of p)', $this->determinismLabel($group, 'mean_stddev')],
            ['argmax flip rate across repeats', $this->determinismLabel($group, 'argmax_flip_rate')],
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
    private function determinismLabel(Collection $group, string $metric): string
    {
        $byCase = [];

        foreach ($group as $row) {
            if (! is_array($row->probabilities)) {
                continue;
            }

            $byCase[$row->case_id.'|'.$row->question_id][] = array_map('floatval', $row->probabilities);
        }

        $stats = MetricsCalculator::determinismStats($byCase);

        if ($stats['repeated_cases'] === 0) {
            return 'n/a (single pass)';
        }

        if ($metric === 'argmax_flip_rate') {
            return $this->pct($stats['argmax_flip_rate'])." of {$stats['repeated_cases']} repeated cases";
        }

        return number_format($stats[$metric], 5);
    }

    /**
     * @param  list<array<string, mixed>>  $scoring
     */
    private function accuracyWithInterval(array $scoring): string
    {
        $total = count($scoring);
        $correct = count(array_filter($scoring, static fn (array $r): bool => (bool) $r['correct']));
        $interval = MetricsCalculator::wilsonInterval($correct, $total);

        return sprintf(
            '%s  (%d/%d, 95%% CI %s–%s)',
            $this->pct($total > 0 ? $correct / $total : 0.0),
            $correct,
            $total,
            $this->pct($interval['low']),
            $this->pct($interval['high']),
        );
    }

    /**
     * Multi-label scoring: every question is a Noul for one label, and a case's
     * answer is the SET of labels above a threshold. Swept rather than fixed,
     * because the useful number is how few labels survive while still keeping
     * essentially all the true ones.
     *
     * @param  Collection<int, DecisionEval>  $rows
     */
    private function multiLabelReport(Collection $rows, ?string $groupBy): void
    {
        $thresholds = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9];

        $grouped = $rows->groupBy(function (DecisionEval $r) use ($groupBy): string {
            $suffix = $groupBy === null ? '' : '|'.$this->metaValue($r, $groupBy);

            return $r->driver.'|'.$r->dataset.$suffix;
        });

        foreach ($grouped->sortKeys() as $key => $group) {
            $parts = explode('|', (string) $key);
            $label = $parts[0].' · '.$parts[1].(isset($parts[2]) ? ' · '.$parts[2] : '');

            $cases = [];
            $labelCount = 0;

            foreach ($group->groupBy('case_id') as $caseId => $caseRows) {
                $scores = [];
                $gold = [];

                foreach ($caseRows as $row) {
                    $slug = (string) $row->question_id;
                    $scores[$slug] = (float) ($row->answer['noul'] ?? 0.0);

                    if (filter_var($row->goldLabel(), FILTER_VALIDATE_BOOLEAN)) {
                        $gold[] = $slug;
                    }
                }

                $labelCount = max($labelCount, count($scores));
                $cases[(string) $caseId] = ['scores' => $scores, 'gold' => $gold];
            }

            $sweep = MetricsCalculator::multiLabelSweep(array_values($cases), $thresholds);

            $this->line("<options=bold>{$label}</> — multi-label over {$labelCount} labels, ".count($cases).' cases');

            if ($sweep === []) {
                $this->warn('  Every case has an empty gold set; nothing to score.');
                $this->newLine();

                continue;
            }

            $table = [];

            foreach ($sweep as $entry) {
                $table[] = [
                    number_format($entry['threshold'], 1),
                    number_format($entry['recall'], 4),
                    number_format($entry['precision'], 4),
                    number_format($entry['kept'], 2).' / '.$labelCount,
                    $this->pct($entry['perfect_recall']),
                ];
            }

            $this->table(['threshold', 'mean recall', 'mean precision', 'mean labels kept', 'cases at full recall'], $table);

            // Headline: the tightest prefilter that still keeps essentially every
            // true label. Anything above it is cheaper but starts dropping work.
            $headline = null;

            foreach ($sweep as $entry) {
                if ($entry['recall'] >= 0.98) {
                    $headline = $entry;
                }
            }

            if ($headline === null) {
                $this->warn('  No threshold reaches mean recall 0.98 — the prefilter cannot be trusted on this dataset.');
            } else {
                $reduction = $labelCount > 0 ? 1 - ($headline['kept'] / $labelCount) : 0.0;
                $this->line(sprintf(
                    '  <options=bold>Headline</>: t=%.1f keeps %.2f of %d domains (%s context reduction) at mean recall %.4f, mean precision %.4f',
                    $headline['threshold'],
                    $headline['kept'],
                    $labelCount,
                    $this->pct($reduction),
                    $headline['recall'],
                    $headline['precision'],
                ));
            }

            $this->newLine();
        }
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
