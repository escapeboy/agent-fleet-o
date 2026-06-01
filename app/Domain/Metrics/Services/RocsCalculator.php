<?php

namespace App\Domain\Metrics\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AiRun;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Metrics\Models\Metric;
use Carbon\CarbonInterface;

/**
 * Return on Cognitive Spend (ROCS).
 *
 * Joins spend ({@see AiRun::$cost_credits}) with delivered value
 * ({@see Metric} rows of a value-bearing type) to produce cost-vs-value and
 * ROI figures per experiment, per agent, and as team totals.
 *
 * Spend is the canonical per-LLM-call credit cost (1 credit =
 * config('llm_pricing.credit_value_usd') USD). Value is stored in cents on
 * Metric.value (matching AttributeRevenueAction), so USD = value / 100.
 *
 * Per-agent value uses spend-proportional attribution: an experiment's value
 * is split across the agents that spent on it, weighted by each agent's share
 * of that experiment's spend. ROI is null when spend is zero (no div-by-zero).
 */
class RocsCalculator
{
    /** Metric types that represent realised value (numerator of ROI). */
    public const VALUE_METRIC_TYPES = ['payment', 'business_value'];

    /**
     * @return array{
     *     summary: array{spend_credits:int, spend_usd:float, value_usd:float, net_usd:float, roi:float|null, experiment_count:int},
     *     by_experiment: list<array{experiment_id:string, title:?string, spend_credits:int, spend_usd:float, value_usd:float, net_usd:float, roi:float|null}>,
     *     by_agent: list<array{agent_id:string, name:?string, spend_credits:int, spend_usd:float, attributed_value_usd:float, net_usd:float, roi:float|null}>
     * }
     */
    public function forTeam(string $teamId, CarbonInterface $since, ?CarbonInterface $until = null): array
    {
        $creditValueUsd = (float) config('llm_pricing.credit_value_usd', 0.001);

        // --- Spend (AiRun.cost_credits) -------------------------------------
        $spendRows = AiRun::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('created_at', '>=', $since)
            ->when($until, fn ($q) => $q->where('created_at', '<=', $until))
            ->where('cost_credits', '>', 0)
            ->get(['agent_id', 'experiment_id', 'cost_credits']);

        $totalSpendCredits = 0;
        $spendByExperiment = [];       // experiment_id => credits
        $spendByAgent = [];            // agent_id => credits
        $spendByAgentExperiment = [];  // agent_id => [experiment_id => credits]

        foreach ($spendRows as $run) {
            $credits = (int) $run->cost_credits;
            $totalSpendCredits += $credits;

            if ($run->experiment_id !== null) {
                $spendByExperiment[$run->experiment_id] = ($spendByExperiment[$run->experiment_id] ?? 0) + $credits;
            }
            if ($run->agent_id !== null) {
                $spendByAgent[$run->agent_id] = ($spendByAgent[$run->agent_id] ?? 0) + $credits;

                if ($run->experiment_id !== null) {
                    $spendByAgentExperiment[$run->agent_id][$run->experiment_id] =
                        ($spendByAgentExperiment[$run->agent_id][$run->experiment_id] ?? 0) + $credits;
                }
            }
        }

        // --- Value (Metric.value, cents) ------------------------------------
        $valueByExperiment = Metric::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereIn('type', self::VALUE_METRIC_TYPES)
            ->whereNotNull('experiment_id')
            ->where('occurred_at', '>=', $since)
            ->when($until, fn ($q) => $q->where('occurred_at', '<=', $until))
            ->groupBy('experiment_id')
            ->selectRaw('experiment_id, SUM(value) as total_cents')
            ->pluck('total_cents', 'experiment_id')
            ->map(fn ($cents) => (float) $cents)
            ->all();

        // --- Per-experiment rows --------------------------------------------
        $experimentIds = array_values(array_unique(array_merge(
            array_keys($spendByExperiment),
            array_keys($valueByExperiment),
        )));

        $titles = Experiment::withoutGlobalScopes()
            ->whereIn('id', $experimentIds)
            ->pluck('title', 'id');

        $byExperiment = [];
        foreach ($experimentIds as $experimentId) {
            $credits = $spendByExperiment[$experimentId] ?? 0;
            $spendUsd = $this->round($credits * $creditValueUsd);
            $valueUsd = $this->round(($valueByExperiment[$experimentId] ?? 0.0) / 100);

            $byExperiment[] = [
                'experiment_id' => $experimentId,
                'title' => $titles[$experimentId] ?? null,
                'spend_credits' => $credits,
                'spend_usd' => $spendUsd,
                'value_usd' => $valueUsd,
                'net_usd' => $this->round($valueUsd - $spendUsd),
                'roi' => $this->roi($valueUsd, $spendUsd),
            ];
        }
        usort($byExperiment, fn ($a, $b) => $b['spend_credits'] <=> $a['spend_credits']);

        // --- Per-agent rows (spend-proportional value attribution) ----------
        $names = Agent::withoutGlobalScopes()
            ->whereIn('id', array_keys($spendByAgent))
            ->pluck('name', 'id');

        $byAgent = [];
        foreach ($spendByAgent as $agentId => $credits) {
            $attributedValueUsd = 0.0;
            foreach (($spendByAgentExperiment[$agentId] ?? []) as $experimentId => $agentExpCredits) {
                $experimentTotal = $spendByExperiment[$experimentId] ?? 0;
                $experimentValueUsd = ($valueByExperiment[$experimentId] ?? 0.0) / 100;
                if ($experimentTotal > 0 && $experimentValueUsd > 0) {
                    $attributedValueUsd += $experimentValueUsd * ($agentExpCredits / $experimentTotal);
                }
            }

            $spendUsd = $this->round($credits * $creditValueUsd);
            $attributedValueUsd = $this->round($attributedValueUsd);

            $byAgent[] = [
                'agent_id' => $agentId,
                'name' => $names[$agentId] ?? null,
                'spend_credits' => $credits,
                'spend_usd' => $spendUsd,
                'attributed_value_usd' => $attributedValueUsd,
                'net_usd' => $this->round($attributedValueUsd - $spendUsd),
                'roi' => $this->roi($attributedValueUsd, $spendUsd),
            ];
        }
        usort($byAgent, fn ($a, $b) => $b['spend_credits'] <=> $a['spend_credits']);

        // --- Team totals ----------------------------------------------------
        $totalSpendUsd = $this->round($totalSpendCredits * $creditValueUsd);
        $totalValueUsd = $this->round(array_sum(array_column($byExperiment, 'value_usd')));

        return [
            'summary' => [
                'spend_credits' => $totalSpendCredits,
                'spend_usd' => $totalSpendUsd,
                'value_usd' => $totalValueUsd,
                'net_usd' => $this->round($totalValueUsd - $totalSpendUsd),
                'roi' => $this->roi($totalValueUsd, $totalSpendUsd),
                'experiment_count' => count($byExperiment),
            ],
            'by_experiment' => $byExperiment,
            'by_agent' => $byAgent,
        ];
    }

    private function roi(float $valueUsd, float $spendUsd): ?float
    {
        if ($spendUsd <= 0.0) {
            return null;
        }

        return round($valueUsd / $spendUsd, 2);
    }

    private function round(float $usd): float
    {
        return round($usd, 2);
    }
}
