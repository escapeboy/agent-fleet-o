<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;

/**
 * Marks a debug-track building stage complete and moves the experiment to
 * AwaitingApproval, recording the opened PR URLs + a summary on the stage.
 *
 * Shared by both completion paths: the external bridge builder (via the
 * experiment_complete_building MCP tool) and the platform-side warm-build
 * executor (ExecuteWarmDebugBuildAction). Both must produce identical state.
 */
class CompleteBuildingAction
{
    /**
     * @param  list<string>  $prUrls
     */
    public function execute(Experiment $experiment, array $prUrls = [], ?string $summary = null, string $completedBy = 'agent_mcp'): void
    {
        $stage = ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('stage', StageType::Building)
            ->where('status', StageStatus::Running)
            ->latest()
            ->first();

        if ($stage) {
            $stage->update([
                'status' => StageStatus::Completed,
                'completed_at' => now(),
                'duration_ms' => $stage->started_at ? (int) $stage->started_at->diffInMilliseconds(now()) : null,
                'output_snapshot' => array_merge($stage->output_snapshot ?? [], array_filter([
                    'pr_urls' => $prUrls,
                    'summary' => $summary,
                    'completed_by' => $completedBy,
                ])),
            ]);
        }

        app(TransitionExperimentAction::class)->execute(
            experiment: $experiment,
            toState: ExperimentStatus::AwaitingApproval,
            reason: 'Building completed — '.count($prUrls).' PR(s) opened',
        );
    }
}
