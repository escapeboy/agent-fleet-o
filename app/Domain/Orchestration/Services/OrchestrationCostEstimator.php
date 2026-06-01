<?php

namespace App\Domain\Orchestration\Services;

use App\Domain\Crew\Models\Crew;
use App\Domain\Workflow\Actions\EstimateWorkflowCostAction;
use App\Domain\Workflow\Models\Workflow;

/**
 * Pre-flight cost estimates (in credits) for orchestration runs, so a fan-out
 * can be priced before it dispatches. Uses the same coarse per-agent profile
 * as {@see EstimateWorkflowCostAction} (1k input @3 + 0.5k output @15 ≈ 11
 * credits/agent-run) — good enough to gate on, not a billing figure.
 */
class OrchestrationCostEstimator
{
    /** Credits for one average agent run (1k in @3 + 0.5k out @15, rounded up). */
    private const CREDITS_PER_AGENT_RUN = 11;

    public function __construct(
        private readonly EstimateWorkflowCostAction $estimateWorkflowCost,
    ) {}

    /**
     * Projected credits for a full crew run: every participating agent
     * (coordinator + QA + workers) across the average number of iterations.
     */
    public function estimateCrew(Crew $crew): int
    {
        $iterationFactor = max(1, (int) ceil($crew->max_task_iterations / 2));

        return $crew->agentCount() * self::CREDITS_PER_AGENT_RUN * $iterationFactor;
    }

    public function estimateWorkflow(Workflow $workflow): int
    {
        return $this->estimateWorkflowCost->execute($workflow);
    }
}
