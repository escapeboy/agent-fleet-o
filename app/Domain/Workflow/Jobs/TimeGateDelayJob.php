<?php

namespace App\Domain\Workflow\Jobs;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Services\WorkflowGraphExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TimeGateDelayJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly string $stepId,
        public readonly string $experimentId,
        public readonly string $workflowNodeId,
    ) {
        $this->onQueue('experiments');
    }

    public function handle(): void
    {
        $step = PlaybookStep::find($this->stepId);

        if (! $step) {
            Log::warning('TimeGateDelayJob: step not found', ['step_id' => $this->stepId]);

            return;
        }

        // Idempotency: only resume if still in waiting_time state
        if ($step->status !== 'waiting_time') {
            Log::info('TimeGateDelayJob: step already resumed', [
                'step_id' => $this->stepId,
                'status' => $step->status,
            ]);

            return;
        }

        $experiment = Experiment::withoutGlobalScopes()->find($this->experimentId);

        if (! $experiment || $experiment->status->isTerminal()) {
            return;
        }

        Log::info('TimeGateDelayJob: resuming time gate', [
            'step_id' => $this->stepId,
            'experiment_id' => $this->experimentId,
        ]);

        $step->update([
            'status' => 'completed',
            'output' => ['_time_gate_resumed_at' => now()->toIso8601String()],
            'completed_at' => now(),
        ]);

        app(WorkflowGraphExecutor::class)->continueAfterBatch($experiment, [$this->workflowNodeId]);
    }
}
