<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Shared\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class UpdateAgentRiskProfileAction
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Compute and persist the risk profile for an agent.
     *
     * Risk score formula (0–100):
     *   failure_rate_7d   * 30  (reliability weight)
     *   cost_percentile   * 25  (cost vs peer agents)
     *   pii_block_rate    * 25  (safety incidents)
     *   guardrail_block   * 20  (safety blocks)
     */
    public function execute(Agent $agent): void
    {
        $now = now();
        $sevenDaysAgo = $now->copy()->subDays(7);

        // 1. Failure rate over last 7 days
        $recentExecutions = AgentExecution::withoutGlobalScopes()
            ->where('agent_id', $agent->id)
            ->where('created_at', '>=', $sevenDaysAgo)
            ->get(['status', 'cost_credits', 'duration_ms']);

        $totalRecent = $recentExecutions->count();
        $failedRecent = $recentExecutions->where('status', 'failed')->count();
        $failureRate7d = $totalRecent > 0 ? $failedRecent / $totalRecent : 0.0;

        // 2. Average cost per run
        $avgCostPerRun = $recentExecutions->whereNotNull('cost_credits')->avg('cost_credits') ?? 0.0;

        // 3. Cost percentile rank (compare against all agents in same team)
        $allAgentAvgCosts = AgentExecution::withoutGlobalScopes()
            ->where('team_id', $agent->team_id)
            ->where('created_at', '>=', $sevenDaysAgo)
            ->whereNotNull('cost_credits')
            ->selectRaw('agent_id, AVG(cost_credits) as avg_cost')
            ->groupBy('agent_id')
            ->pluck('avg_cost', 'agent_id')
            ->toArray();

        $costPercentile = 0.0;
        if (count($allAgentAvgCosts) > 1 && $avgCostPerRun > 0) {
            $lowerCount = collect($allAgentAvgCosts)->filter(fn ($c) => $c < $avgCostPerRun)->count();
            $costPercentile = $lowerCount / count($allAgentAvgCosts);
        }

        // 4. Guardrail block rate (steps with guardrail_result.safe = false)
        $stepsWithGuardrail = PlaybookStep::withoutGlobalScopes()
            ->where('agent_id', $agent->id)
            ->whereNotNull('guardrail_result')
            ->where('created_at', '>=', $sevenDaysAgo)
            ->get(['guardrail_result']);

        $totalGuardrailChecks = $stepsWithGuardrail->count();
        $guardrailBlocks = $stepsWithGuardrail->filter(function ($step) {
            $result = $step->guardrail_result;

            return is_array($result) && ($result['safe'] ?? true) === false;
        })->count();
        $guardrailBlockRate = $totalGuardrailChecks > 0 ? $guardrailBlocks / $totalGuardrailChecks : 0.0;

        // 5. PII detection rate (guardrail blocks for PII specifically)
        $piiBlocks = $stepsWithGuardrail->filter(function ($step) {
            $result = $step->guardrail_result;

            return is_array($result)
                && ($result['safe'] ?? true) === false
                && str_contains($result['reason'] ?? '', 'PII');
        })->count();
        $piiDetectionRate = $totalGuardrailChecks > 0 ? $piiBlocks / $totalGuardrailChecks : 0.0;

        // 6. Determine cost volatility
        $costValues = $recentExecutions->whereNotNull('cost_credits')->pluck('cost_credits');
        $costVolatility = 'low';
        if ($costValues->count() > 2) {
            $mean = $costValues->average();
            $stdDev = sqrt($costValues->map(fn ($c) => pow($c - $mean, 2))->average() ?? 0);
            $cv = $mean > 0 ? ($stdDev / $mean) : 0;
            $costVolatility = $cv > 0.5 ? 'high' : ($cv > 0.25 ? 'medium' : 'low');
        }

        // 7. Determine risk factors
        $riskFactors = [];
        if ($failureRate7d > 0.2) {
            $riskFactors[] = 'high_failure_rate';
        }
        if ($costPercentile > 0.8) {
            $riskFactors[] = 'high_cost';
        }
        if ($costVolatility === 'high') {
            $riskFactors[] = 'high_cost_variance';
        }
        if ($guardrailBlockRate > 0.1) {
            $riskFactors[] = 'frequent_guardrail_blocks';
        }
        if ($piiDetectionRate > 0.05) {
            $riskFactors[] = 'pii_exposure_risk';
        }

        // 8. Compute final risk score (0–100)
        $riskScore = round(
            ($failureRate7d * 30)
            + ($costPercentile * 25)
            + ($piiDetectionRate * 25)
            + ($guardrailBlockRate * 20),
            2,
        );

        $riskProfile = [
            'failure_rate_7d' => round($failureRate7d, 4),
            'avg_cost_per_run' => round($avgCostPerRun, 2),
            'cost_volatility' => $costVolatility,
            'guardrail_block_rate' => round($guardrailBlockRate, 4),
            'pii_detection_rate' => round($piiDetectionRate, 4),
            'last_updated' => $now->toIso8601String(),
            'risk_factors' => $riskFactors,
        ];

        $agent->update([
            'risk_score' => $riskScore,
            'risk_profile' => $riskProfile,
            'risk_profile_updated_at' => $now,
        ]);

        Log::info('UpdateAgentRiskProfileAction: updated', [
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'risk_score' => $riskScore,
            'risk_factors' => $riskFactors,
        ]);

        // Auto-disable agent if risk score exceeds 80
        if ($riskScore > 80 && $agent->status === AgentStatus::Active) {
            app(DisableAgentAction::class)->execute($agent, 'Auto-disabled: risk score exceeded 80 (score: '.$riskScore.')');

            // Notify team
            $this->notificationService->notifyTeam(
                teamId: $agent->team_id,
                type: 'agent_risk_critical',
                title: "Agent '{$agent->name}' auto-disabled",
                body: "Risk score reached {$riskScore}/100. Risk factors: ".implode(', ', $riskFactors).'. Please review and re-enable when issues are resolved.',
                actionUrl: '/agents/'.$agent->id,
            );
        }
    }
}
