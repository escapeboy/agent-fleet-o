<?php

namespace App\Infrastructure\AI\Services;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\DB;

/**
 * Eval-grounded model recommendation (Cast AI "AI Enabler" borrow), SHADOW only.
 *
 * For a request's task-type (purpose), looks at recent recorded quality
 * outcomes per model — `ai_runs.verification_passed` / `schema_valid` /
 * completion are the eval gate already persisted per run — and recommends the
 * CHEAPEST model whose success rate clears the configured threshold.
 *
 * We source the quality signal from `ai_runs` rather than `evaluation_runs`
 * because eval runs evaluate agents/workflows and store no "model-under-test"
 * dimension, whereas every ai_run records (purpose, provider, model, cost,
 * pass/fail) — exactly the (task-type × model) grid this needs.
 *
 * Returns null when the feature is off, there's no team, or no model has
 * enough samples clearing the bar. The caller never mutates routing from this.
 */
class EvalGroundedModelRecommender
{
    /**
     * @return array{
     *   purpose: string,
     *   recommended_provider: string,
     *   recommended_model: string,
     *   recommended_avg_cost: float,
     *   recommended_success_rate: float,
     *   sample_size: int,
     *   scope: string,
     *   chosen_model: string,
     *   chosen_avg_cost: float|null,
     *   would_downgrade: bool,
     *   est_savings_per_call: float
     * }|null
     */
    public function recommend(AiRequestDTO $request): ?array
    {
        if (! config('ai_routing.eval_grounded.enabled')) {
            return null;
        }

        if (! $request->teamId) {
            return null;
        }

        $complexity = $request->classifiedComplexity;
        $purpose = $request->purpose ?: ('tier:'.($complexity !== null ? $complexity->value : 'unknown'));
        $windowDays = (int) config('ai_routing.eval_grounded.window_days', 30);
        $minSamples = (int) config('ai_routing.eval_grounded.min_samples', 20);
        $threshold = (float) config('ai_routing.eval_grounded.success_threshold', 0.9);
        $cutoff = now()->subDays($windowDays);

        $rows = $this->scoreModels($purpose, $cutoff, $request->teamId);
        $scope = 'team';

        $teamSampleTotal = (int) array_sum(array_map(static fn ($r) => (int) $r->total, $rows));
        if ($teamSampleTotal < $minSamples && config('ai_routing.eval_grounded.platform_fallback', true)) {
            $rows = $this->scoreModels($purpose, $cutoff, null);
            $scope = 'platform';
        }

        if ($rows === []) {
            return null;
        }

        $candidates = array_filter(
            $rows,
            static fn ($r) => (int) $r->total >= $minSamples && (float) $r->success_rate >= $threshold,
        );

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn ($a, $b) => (float) $a->avg_cost <=> (float) $b->avg_cost);
        $best = $candidates[0];

        $chosen = null;
        foreach ($rows as $r) {
            if ($r->provider === $request->provider && $r->model === $request->model) {
                $chosen = $r;
                break;
            }
        }
        $chosenAvgCost = $chosen !== null ? (float) $chosen->avg_cost : null;

        $wouldDowngrade = $chosenAvgCost !== null && (float) $best->avg_cost < $chosenAvgCost;
        $estSaving = $wouldDowngrade ? max(0.0, $chosenAvgCost - (float) $best->avg_cost) : 0.0;

        return [
            'purpose' => $purpose,
            'recommended_provider' => (string) $best->provider,
            'recommended_model' => (string) $best->model,
            'recommended_avg_cost' => round((float) $best->avg_cost, 2),
            'recommended_success_rate' => round((float) $best->success_rate, 3),
            'sample_size' => (int) $best->total,
            'scope' => $scope,
            'chosen_model' => "{$request->provider}/{$request->model}",
            'chosen_avg_cost' => $chosenAvgCost !== null ? round($chosenAvgCost, 2) : null,
            'would_downgrade' => $wouldDowngrade,
            'est_savings_per_call' => round($estSaving, 2),
        ];
    }

    /**
     * @return list<object{provider: string, model: string, total: int, success_rate: float, avg_cost: float}>
     */
    private function scoreModels(string $purpose, \DateTimeInterface $cutoff, ?string $teamId): array
    {
        // Query builder (not the Eloquent model) so the aggregate aliases are
        // plain stdClass attributes — keeps larastan's checkModelProperties off
        // our back and lets withoutGlobalScopes + explicit team_id be the only
        // tenancy filter regardless of console/queue context.
        $query = DB::table('ai_runs')
            ->where('purpose', $purpose)
            ->where('created_at', '>=', $cutoff);

        if ($teamId !== null) {
            $query->where('team_id', $teamId);
        }

        return $query->select(
            'provider',
            'model',
            DB::raw('COUNT(*) as total'),
            DB::raw($this->successExpression().' as success_rate'),
            DB::raw('AVG(cost_credits) as avg_cost'),
        )
            ->groupBy('provider', 'model')
            ->get()
            ->all();
    }

    /**
     * Fraction of runs that completed AND were not flagged as a quality failure.
     * NULL verification/schema means "not gated" → counts as a pass. Built per
     * driver because PostgreSQL booleans and SQLite 0/1 ints differ.
     */
    private function successExpression(): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return "AVG(CASE WHEN status = 'completed' AND verification_passed IS NOT FALSE AND schema_valid IS NOT FALSE THEN 1.0 ELSE 0.0 END)";
        }

        return "AVG(CASE WHEN status = 'completed' AND COALESCE(verification_passed, 1) = 1 AND COALESCE(schema_valid, 1) = 1 THEN 1.0 ELSE 0.0 END)";
    }
}
