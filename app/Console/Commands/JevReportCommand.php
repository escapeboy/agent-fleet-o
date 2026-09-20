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

    private const MAX_CONFUSION_PAIRS = 12;

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
            ['accuracy', $this->accuracyCell($group, $scoring)],
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

        $this->typeDetail($group, $scoring);

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
     * Accuracy alone says nothing on a Noul question until you know how often
     * the gold answer is true — 90% accuracy on a question whose gold is false
     * 90% of the time is the constant "no".
     *
     * @param  Collection<int, DecisionEval>  $group
     * @param  list<array<string, mixed>>  $scoring
     */
    private function accuracyCell(Collection $group, array $scoring): string
    {
        $cell = $this->accuracyWithInterval($scoring);

        if ($this->answerType($group) !== 'noul') {
            return $cell;
        }

        $positives = $group->filter(fn (DecisionEval $r): bool => $this->goldIsTrue($r))->count();

        return $cell.'  · positive rate '.$this->pct($group->count() > 0 ? $positives / $group->count() : 0.0);
    }

    /**
     * @param  Collection<int, DecisionEval>  $group
     */
    private function answerType(Collection $group): string
    {
        $answer = $group->first()->answer ?? [];

        return (string) ($answer['type'] ?? '');
    }

    private function goldIsTrue(DecisionEval $row): bool
    {
        /** @var mixed $gold */
        $gold = $row->gold;

        if (is_bool($gold)) {
            return $gold;
        }

        if (is_numeric($gold)) {
            return (float) $gold >= 0.5;
        }

        return is_string($gold) && filter_var($gold, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Per-question-type detail. The headline table is deliberately uniform so
     * drivers stay comparable; everything that only makes sense for one answer
     * type lives here.
     *
     * @param  Collection<int, DecisionEval>  $group
     * @param  list<array<string, mixed>>  $scoring
     */
    private function typeDetail(Collection $group, array $scoring): void
    {
        match ($this->answerType($group)) {
            'choice' => $this->choiceDetail($group, $scoring),
            'noul' => $this->noulDetail($group),
            'score' => $this->scoreDetail($group),
            default => null,
        };
    }

    /**
     * @param  Collection<int, DecisionEval>  $group
     * @param  list<array<string, mixed>>  $scoring
     */
    private function choiceDetail(Collection $group, array $scoring): void
    {
        $scored = 0;
        $hits = 0;

        foreach ($group as $row) {
            $probabilities = $row->probabilities;

            if (! is_array($probabilities) || $probabilities === []) {
                continue;
            }

            $ranked = array_map('floatval', $probabilities);
            arsort($ranked);
            $scored++;

            if (in_array($row->goldLabel(), array_slice(array_keys($ranked), 0, 2), true)) {
                $hits++;
            }
        }

        if ($scored === 0) {
            $this->line('  top-2 accuracy: n/a (no probabilities)');
        } else {
            $interval = MetricsCalculator::wilsonInterval($hits, $scored);
            $this->line(sprintf(
                '  top-2 accuracy: %s  (%d/%d, 95%% CI %s–%s)',
                $this->pct($hits / $scored),
                $hits,
                $scored,
                $this->pct($interval['low']),
                $this->pct($interval['high']),
            ));
        }

        $this->perClassTable($scoring);
        $this->confusionTable($scoring);
    }

    /**
     * @param  list<array<string, mixed>>  $scoring
     */
    private function perClassTable(array $scoring): void
    {
        $labels = [];

        foreach ($scoring as $row) {
            $labels[(string) $row['gold']] = true;
            $labels[(string) $row['predicted']] = true;
        }

        $rows = [];

        foreach (array_keys($labels) as $key) {
            // array_keys() returns an int for a numeric-string key.
            $label = (string) $key;
            $tp = $fp = $fn = 0;

            foreach ($scoring as $row) {
                $gold = (string) $row['gold'];
                $predicted = (string) $row['predicted'];

                if ($predicted === $label && $gold === $label) {
                    $tp++;
                } elseif ($predicted === $label) {
                    $fp++;
                } elseif ($gold === $label) {
                    $fn++;
                }
            }

            $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
            $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
            $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;

            $rows[] = [$label === '' ? '(empty)' : $label, (string) ($tp + $fn), (string) ($tp + $fp), $this->pct($precision), $this->pct($recall), number_format($f1, 3)];
        }

        usort($rows, static fn (array $a, array $b): int => (int) $b[1] <=> (int) $a[1]);

        $this->line('  per-class precision / recall / F1:');
        $this->table(['class', 'gold n', 'predicted n', 'precision', 'recall', 'F1'], $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $scoring
     */
    private function confusionTable(array $scoring): void
    {
        $pairs = [];

        foreach ($scoring as $row) {
            $gold = (string) $row['gold'];
            $predicted = (string) $row['predicted'];

            if ($gold === $predicted) {
                continue;
            }

            $key = $gold.' → '.($predicted === '' ? '(empty)' : $predicted);
            $pairs[$key] = ($pairs[$key] ?? 0) + 1;
        }

        if ($pairs === []) {
            return;
        }

        arsort($pairs);

        $rows = [];

        foreach (array_slice($pairs, 0, self::MAX_CONFUSION_PAIRS, true) as $pair => $count) {
            $rows[] = [$pair, (string) $count];
        }

        $this->line('  confusion pairs (gold → predicted), most frequent first:');
        $this->table(['pair', 'n'], $rows);
    }

    /**
     * @param  Collection<int, DecisionEval>  $group
     */
    private function noulDetail(Collection $group): void
    {
        $points = [];

        foreach ($group as $row) {
            $answer = $row->answer ?? [];
            $points[] = [
                'p' => (float) ($answer['noul'] ?? 0.0),
                'gold' => $this->goldIsTrue($row),
            ];
        }

        $tp = $fp = $fn = 0;

        foreach ($points as $point) {
            $predicted = $point['p'] >= 0.5;

            if ($predicted && $point['gold']) {
                $tp++;
            } elseif ($predicted) {
                $fp++;
            } elseif ($point['gold']) {
                $fn++;
            }
        }

        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;

        $this->line('  positive class (statement is true):');
        $this->table(['metric', 'value'], [
            ['positive rate (gold)', $this->pct(count($points) > 0 ? ($tp + $fn) / count($points) : 0.0)],
            ['precision @ t=0.5', $this->pct($precision)." ({$tp}/".($tp + $fp).')'],
            ['recall @ t=0.5', $this->pct($recall)." ({$tp}/".($tp + $fn).')'],
            ['F1 @ t=0.5', number_format($f1, 4)],
            ['PR-AUC (average precision)', number_format($this->averagePrecision($points), 4)],
        ]);
    }

    /**
     * Average precision — the area under the precision/recall curve, computed
     * by walking the cases in descending predicted probability and summing
     * precision at every point where recall actually increases. Threshold-free,
     * so it survives a driver whose probabilities are well ordered but badly
     * scaled.
     *
     * @param  list<array{p: float, gold: bool}>  $points
     */
    private function averagePrecision(array $points): float
    {
        $positives = count(array_filter($points, static fn (array $p): bool => $p['gold']));

        if ($positives === 0) {
            return 0.0;
        }

        usort($points, static fn (array $a, array $b): int => $b['p'] <=> $a['p']);

        $tp = 0;
        $seen = 0;
        $sum = 0.0;

        foreach ($points as $point) {
            $seen++;

            if ($point['gold']) {
                $tp++;
                $sum += $tp / $seen;
            }
        }

        return $sum / $positives;
    }

    /**
     * @param  Collection<int, DecisionEval>  $group
     */
    private function scoreDetail(Collection $group): void
    {
        $absoluteError = 0.0;
        $scored = 0;
        $binaryCorrect = 0;

        foreach ($group as $row) {
            $answer = $row->answer ?? [];
            /** @var mixed $gold */
            $gold = $row->gold;

            if (! is_numeric($gold)) {
                continue;
            }

            $predicted = round((float) ($answer['score'] ?? 0.0));
            $scored++;
            $absoluteError += abs($predicted - (float) $gold);

            if (($predicted > 0) === ((float) $gold > 0)) {
                $binaryCorrect++;
            }
        }

        if ($scored === 0) {
            return;
        }

        $interval = MetricsCalculator::wilsonInterval($binaryCorrect, $scored);

        $this->line('  ordered-level detail:');
        $this->table(['metric', 'value'], [
            ['MAE (level index)', number_format($absoluteError / $scored, 4)],
            ['binary accuracy (level 0 vs > 0)', sprintf(
                '%s  (%d/%d, 95%% CI %s–%s)',
                $this->pct($binaryCorrect / $scored),
                $binaryCorrect,
                $scored,
                $this->pct($interval['low']),
                $this->pct($interval['high']),
            )],
        ]);
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
        [$predicted, $gold] = $this->labels($row, $answer);
        $probabilities = $row->probabilities;

        $predictedProbability = is_array($probabilities) && array_key_exists($predicted, $probabilities)
            ? (float) $probabilities[$predicted]
            : (is_array($probabilities) && $probabilities !== [] ? max(array_map('floatval', $probabilities)) : $row->confidence);

        return [
            'correct' => (bool) $row->correct,
            'confidence' => $row->confidence ?? $predictedProbability,
            'predicted' => $predicted,
            'gold' => $gold,
            'predicted_probability' => $predictedProbability,
            'latency_ms' => (int) $row->latency_ms,
        ];
    }

    /**
     * The label pair every class-based metric compares — macro-F1, the
     * per-class table, the confusion pairs.
     *
     * A Score answer is a continuous position on the scale (0.18), not a level,
     * and a Noul answer is a probability (0.03), not a verdict. Comparing those
     * raw against a gold level index or boolean never matches, which silently
     * reported macro-F1 0.0000 next to 96% accuracy. Both sides are reduced to
     * the label the answer actually asserts, the same way `jev:eval` scores
     * correctness.
     *
     * @param  array<string, mixed>  $answer
     * @return array{0: string, 1: string}
     */
    private function labels(DecisionEval $row, array $answer): array
    {
        /** @var mixed $gold */
        $gold = $row->gold;

        return match ((string) ($answer['type'] ?? '')) {
            'score' => [
                (string) (int) round((float) ($answer['score'] ?? 0.0)),
                is_numeric($gold) ? (string) (int) round((float) $gold) : $row->goldLabel(),
            ],
            'noul' => [
                (float) ($answer['noul'] ?? 0.0) >= 0.5 ? 'true' : 'false',
                $this->goldIsTrue($row) ? 'true' : 'false',
            ],
            default => [(string) ($answer['choice'] ?? ''), $row->goldLabel()],
        };
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
