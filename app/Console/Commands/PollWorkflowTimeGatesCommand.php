<?php

namespace App\Console\Commands;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Services\WorkflowGraphExecutor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PollWorkflowTimeGatesCommand extends Command
{
    protected $signature = 'workflows:poll-time-gates';

    protected $description = 'Resume workflow time gate steps whose delay has expired (durability fallback)';

    public function handle(WorkflowGraphExecutor $executor): int
    {
        $expired = PlaybookStep::where('status', 'waiting_time')
            ->whereNotNull('resume_at')
            ->where('resume_at', '<=', now())
            ->get();

        if ($expired->isEmpty()) {
            return self::SUCCESS;
        }

        $this->info("Found {$expired->count()} expired time gate(s) to resume.");

        foreach ($expired as $step) {
            $experiment = Experiment::withoutGlobalScopes()->find($step->experiment_id);

            if (! $experiment || $experiment->status->isTerminal()) {
                // Mark step failed if experiment is dead
                $step->update([
                    'status' => 'failed',
                    'error_message' => 'Experiment terminated while time gate was active',
                    'completed_at' => now(),
                ]);

                continue;
            }

            $step->update([
                'status' => 'completed',
                'output' => ['_time_gate_resumed_at' => now()->toIso8601String()],
                'completed_at' => now(),
            ]);

            try {
                $executor->continueAfterBatch($experiment, [$step->workflow_node_id]);
            } catch (\Throwable $e) {
                Log::error('PollWorkflowTimeGatesCommand: failed to resume', [
                    'step_id' => $step->id,
                    'experiment_id' => $step->experiment_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
