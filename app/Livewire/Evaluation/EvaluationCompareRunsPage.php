<?php

namespace App\Livewire\Evaluation;

use App\Domain\Evaluation\Models\EvaluationCase;
use App\Domain\Evaluation\Models\EvaluationRun;
use App\Domain\Evaluation\Models\EvaluationRunResult;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Compare two EvaluationRuns side-by-side — aggregate scores, per-case
 * score delta, error diff. Closes Sprint 11's regression-gate workflow:
 *   curate → replay config A → replay config B → compare here.
 */
class EvaluationCompareRunsPage extends Component
{
    #[Url]
    public string $runA = '';

    #[Url]
    public string $runB = '';

    public function render(): View
    {
        $teamId = auth()->user()->current_team_id;

        /** @var EvaluationRun|null $runA */
        $runA = $this->runA !== '' ? EvaluationRun::where('team_id', $teamId)->find($this->runA) : null;
        /** @var EvaluationRun|null $runB */
        $runB = $this->runB !== '' ? EvaluationRun::where('team_id', $teamId)->find($this->runB) : null;

        $perCaseDiff = [];
        if ($runA && $runB) {
            $perCaseDiff = $this->buildPerCaseDiff($runA, $runB);
        }

        $candidateRuns = EvaluationRun::where('team_id', $teamId)
            ->orderByDesc('created_at')
            ->limit(40)
            ->get(['id', 'dataset_id', 'status', 'aggregate_scores', 'summary', 'created_at']);

        return view('livewire.evaluation.evaluation-compare-runs-page', [
            'runA' => $runA,
            'runB' => $runB,
            'perCaseDiff' => $perCaseDiff,
            'candidateRuns' => $candidateRuns,
            'scoreDelta' => $this->overallDelta($runA, $runB),
        ])->layout('layouts.app', ['header' => 'Compare Evaluation Runs']);
    }

    /**
     * @return list<array{case_id: ?string, input: string, expected: string, a_score: ?float, b_score: ?float, delta: ?float, a_error: ?string, b_error: ?string}>
     */
    private function buildPerCaseDiff(EvaluationRun $runA, EvaluationRun $runB): array
    {
        $resultsA = EvaluationRunResult::where('run_id', $runA->id)
            ->whereNotNull('case_id')
            ->get(['case_id', 'score', 'error', 'actual_output']);
        $resultsB = EvaluationRunResult::where('run_id', $runB->id)
            ->whereNotNull('case_id')
            ->get(['case_id', 'score', 'error', 'actual_output']);

        $byCase = [];
        foreach ($resultsA as $r) {
            $byCase[$r->case_id]['a'] = $r;
        }
        foreach ($resultsB as $r) {
            $byCase[$r->case_id]['b'] = $r;
        }

        $caseIds = array_keys($byCase);
        if ($caseIds === []) {
            return [];
        }

        $cases = EvaluationCase::whereIn('id', $caseIds)
            ->get(['id', 'input', 'expected_output'])
            ->keyBy('id');

        $out = [];
        foreach ($byCase as $caseId => $pair) {
            $a = $pair['a'] ?? null;
            $b = $pair['b'] ?? null;
            $aScore = $a?->score !== null ? (float) $a->score : null;
            $bScore = $b?->score !== null ? (float) $b->score : null;
            $out[] = [
                'case_id' => $caseId,
                'input' => (string) ($cases[$caseId]->input ?? ''),
                'expected' => (string) ($cases[$caseId]->expected_output ?? ''),
                'a_score' => $aScore,
                'b_score' => $bScore,
                'delta' => ($aScore !== null && $bScore !== null) ? round($bScore - $aScore, 2) : null,
                'a_error' => $a?->error,
                'b_error' => $b?->error,
            ];
        }

        // Sort: biggest regressions first (largest negative delta).
        usort($out, fn ($x, $y) => ($x['delta'] ?? 0) <=> ($y['delta'] ?? 0));

        return $out;
    }

    private function overallDelta(?EvaluationRun $a, ?EvaluationRun $b): ?float
    {
        if (! $a || ! $b) {
            return null;
        }
        $aAvg = (float) ($a->summary['overall_avg_score'] ?? 0);
        $bAvg = (float) ($b->summary['overall_avg_score'] ?? 0);

        return round($bAvg - $aAvg, 2);
    }
}
