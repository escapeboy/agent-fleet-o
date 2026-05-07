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

    protected string $description = <<<'TXT'
Experiments — the platform's core unit of work. Each experiment runs a workflow (DAG) through a 20-state machine: Draft → Scoring → Planning → Building → AwaitingApproval → Approved → Executing → CollectingMetrics → Evaluating → (Iterating | Completed). Lifecycle transitions are validated by `ExperimentTransitionMap`; use `valid_transitions` to discover what's currently allowed.

Actions:
- list (read) — optional: status, workflow_id, limit.
- get (read) — experiment_id.
- create (write) — name, hypothesis; optional workflow_id (else uses default workflow).
- start (write) — experiment_id. Transitions Draft → Scoring; reserves budget.
- pause / resume (write) — experiment_id. Pause holds at the current stage.
- retry (write) — experiment_id. Re-runs the failed stage.
- retry_from_step (write) — experiment_id, step_id. Graph-aware BFS reset of step + downstream.
- kill (DESTRUCTIVE) — experiment_id. Terminal; cannot resume.
- valid_transitions (read) — experiment_id. Allowed next states for current state.
- cost / steps / share (read) — experiment_id. Cost breakdown / step list / public share token.
TXT;

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
