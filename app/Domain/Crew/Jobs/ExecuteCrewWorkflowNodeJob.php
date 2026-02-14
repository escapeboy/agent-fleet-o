<?php

namespace App\Domain\Crew\Jobs;

use App\Domain\Crew\Actions\ExecuteCrewAction;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Jobs\Middleware\CheckBudgetAvailable;
use App\Jobs\Middleware\CheckKillSwitch;
use App\Jobs\Middleware\TenantRateLimit;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Executes a crew as a workflow graph node.
 *
 * When a workflow contains a Crew node, this job:
 * 1. Runs the crew via ExecuteCrewAction
 * 2. Polls until the crew execution completes
 * 3. Maps the crew result back to the PlaybookStep output
 */
class ExecuteCrewWorkflowNodeJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900; // 15 min — crew executions can be long

    public function __construct(
        public readonly string $stepId,
        public readonly string $experimentId,
        public readonly ?string $teamId = null,
    ) {
        $this->onQueue('experiments');
    }

    public function middleware(): array
    {
        return [
            new CheckKillSwitch,
            new CheckBudgetAvailable,
            new TenantRateLimit('experiments', 30),
        ];
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $step = PlaybookStep::find($this->stepId);

        if (! $step || ! $step->isPending()) {
            return;
        }

        $experiment = Experiment::withoutGlobalScopes()->find($this->experimentId);

        if (! $experiment) {
            return;
        }

        $crew = Crew::withoutGlobalScopes()->find($step->crew_id);

        if (! $crew) {
            $step->update([
                'status' => 'failed',
                'error_message' => 'Crew not found',
                'completed_at' => now(),
            ]);

            return;
        }

        $step->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            // Build goal from input context
            $goal = $this->buildGoalFromContext($step, $experiment);

            // Execute the crew
            $execution = app(ExecuteCrewAction::class)->execute(
                crew: $crew,
                goal: $goal,
                userId: $experiment->user_id,
                experimentId: $experiment->id,
            );

            // Poll until crew execution completes (with timeout)
            $result = $this->waitForCompletion($execution);

            $step->update([
                'status' => $result['status'] === 'completed' ? 'completed' : 'failed',
                'output' => $result['output'],
                'duration_ms' => $result['duration_ms'],
                'cost_credits' => $result['cost_credits'],
                'error_message' => $result['error'],
                'completed_at' => now(),
            ]);

            if ($result['status'] !== 'completed') {
                throw new \RuntimeException("Crew execution failed: {$result['error']}");
            }
        } catch (\Throwable $e) {
            $step->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            Log::error('ExecuteCrewWorkflowNodeJob: Crew execution failed', [
                'step_id' => $this->stepId,
                'experiment_id' => $this->experimentId,
                'crew_id' => $crew->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function buildGoalFromContext(PlaybookStep $step, Experiment $experiment): string
    {
        $input = $step->input_mapping;

        if (! empty($input) && isset($input['goal'])) {
            return $input['goal'];
        }

        // Use node config or experiment context as goal
        $config = $step->config ?? [];

        if (! empty($config['goal'])) {
            return $config['goal'];
        }

        // Fallback: describe the experiment context
        $constraints = $experiment->constraints ?? [];

        return sprintf(
            'Execute the assigned tasks for experiment "%s". Context: %s',
            $experiment->name ?? $experiment->id,
            json_encode(array_slice($constraints, 0, 5)),
        );
    }

    private function waitForCompletion(CrewExecution $execution): array
    {
        $startTime = now();
        $maxWaitSeconds = $this->timeout - 30; // Leave 30s buffer

        while (true) {
            $execution->refresh();

            if ($execution->status === CrewExecutionStatus::Completed) {
                return [
                    'status' => 'completed',
                    'output' => $execution->result,
                    'duration_ms' => $startTime->diffInMilliseconds(now()),
                    'cost_credits' => $execution->total_cost_credits,
                    'error' => null,
                ];
            }

            if ($execution->status === CrewExecutionStatus::Failed
                || $execution->status === CrewExecutionStatus::Terminated) {
                return [
                    'status' => 'failed',
                    'output' => $execution->result,
                    'duration_ms' => $startTime->diffInMilliseconds(now()),
                    'cost_credits' => $execution->total_cost_credits,
                    'error' => $execution->error ?? 'Crew execution '.$execution->status->value,
                ];
            }

            // Check timeout
            if ($startTime->diffInSeconds(now()) > $maxWaitSeconds) {
                return [
                    'status' => 'failed',
                    'output' => null,
                    'duration_ms' => $startTime->diffInMilliseconds(now()),
                    'cost_credits' => $execution->total_cost_credits,
                    'error' => 'Crew execution timed out',
                ];
            }

            sleep(5);
        }
    }
}
