<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Experiment\ExperimentCostTool;
use App\Mcp\Tools\Experiment\ExperimentCreateTool;
use App\Mcp\Tools\Experiment\ExperimentGetTool;
use App\Mcp\Tools\Experiment\ExperimentKillTool;
use App\Mcp\Tools\Experiment\ExperimentListTool;
use App\Mcp\Tools\Experiment\ExperimentPauseTool;
use App\Mcp\Tools\Experiment\ExperimentResumeTool;
use App\Mcp\Tools\Experiment\ExperimentRetryFromStepTool;
use App\Mcp\Tools\Experiment\ExperimentRetryTool;
use App\Mcp\Tools\Experiment\ExperimentShareTool;
use App\Mcp\Tools\Experiment\ExperimentStartTool;
use App\Mcp\Tools\Experiment\ExperimentStepsTool;
use App\Mcp\Tools\Experiment\ExperimentValidTransitionsTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ExperimentManageTool extends CompactTool
{
    protected string $name = 'experiment_manage';

    protected string $description = 'Manage experiments (pipeline runs). Actions: list, get (experiment_id), create (name, hypothesis, workflow_id), start (experiment_id), pause, resume, retry, retry_from_step (experiment_id, step_id), kill, valid_transitions (experiment_id), cost (experiment_id), steps (experiment_id), share (experiment_id).';

    protected function toolMap(): array
    {
        return [
            'list' => ExperimentListTool::class,
            'get' => ExperimentGetTool::class,
            'create' => ExperimentCreateTool::class,
            'start' => ExperimentStartTool::class,
            'pause' => ExperimentPauseTool::class,
            'resume' => ExperimentResumeTool::class,
            'retry' => ExperimentRetryTool::class,
            'retry_from_step' => ExperimentRetryFromStepTool::class,
            'kill' => ExperimentKillTool::class,
            'valid_transitions' => ExperimentValidTransitionsTool::class,
            'cost' => ExperimentCostTool::class,
            'steps' => ExperimentStepsTool::class,
            'share' => ExperimentShareTool::class,
        ];
    }
}
