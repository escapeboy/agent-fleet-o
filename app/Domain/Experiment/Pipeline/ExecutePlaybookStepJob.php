<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Agent\Actions\ExecuteAgentAction;
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

class ExecutePlaybookStepJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;          // Must match or exceed Horizon supervisor-ai-calls tries
    public int $timeout = 1200;     // 20 min — must be LONGER than HTTP timeout (~960s) but shorter than retry_after (1900s)
    public int $backoff = 10;       // Wait 10s before retry (gives bridge time to finish previous request)
    public int $maxExceptions = 2;  // Allow up to 2 exceptions before giving up

    public function __construct(
        public readonly string $stepId,
        public readonly string $experimentId,
        public readonly ?string $teamId = null,
    ) {
        $this->onQueue('ai-calls');
    }

    public function middleware(): array
    {
        return [
            new CheckKillSwitch(),
            new CheckBudgetAvailable(),
            new TenantRateLimit('experiments', 30),
        ];
    }

    public function handle(ExecuteAgentAction $executeAgent): void
    {
        Log::info("ExecutePlaybookStepJob: starting", [
            'step_id' => $this->stepId,
            'attempt' => $this->attempts(),
        ]);

        if ($this->batch()?->cancelled()) {
            return;
        }

        $step = PlaybookStep::find($this->stepId);

        if (! $step) {
            return;
        }

        // If step is already completed or failed, nothing to do
        if ($step->isCompleted() || $step->isFailed()) {
            return;
        }

        // If step is "running", a previous attempt started but died without completing.
        // Fail the step so the pipeline can handle the error properly.
        if ($step->isRunning()) {
            Log::warning("ExecutePlaybookStepJob: step found in 'running' state on attempt {$this->attempts()}, failing it", [
                'step_id' => $this->stepId,
                'attempt' => $this->attempts(),
            ]);

            $step->update([
                'status' => 'failed',
                'error_message' => 'Previous execution attempt timed out or was interrupted',
                'completed_at' => now(),
            ]);

            throw new \RuntimeException('Step execution timed out on previous attempt');
        }

        if (! $step->isPending()) {
            return;
        }

        $experiment = Experiment::withoutGlobalScopes()->find($this->experimentId);

        if (! $experiment) {
            return;
        }

        $step->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $input = $this->resolveInput($step, $experiment);

            Log::info("ExecutePlaybookStepJob: calling executeAgent", [
                'step_id' => $this->stepId,
                'agent' => $step->agent?->name,
                'input_keys' => array_keys($input),
                'attempt' => $this->attempts(),
            ]);

            $result = $executeAgent->execute(
                agent: $step->agent,
                input: $input,
                teamId: $experiment->team_id,
                userId: $experiment->user_id,
                experimentId: $experiment->id,
                stepId: $this->stepId,
            );

            Log::info("ExecutePlaybookStepJob: completed", [
                'step_id' => $this->stepId,
                'success' => $result['output'] !== null,
                'duration_ms' => $result['execution']->duration_ms ?? 0,
            ]);

            $step->update([
                'status' => $result['output'] !== null ? 'completed' : 'failed',
                'output' => $result['output'],
                'duration_ms' => $result['execution']->duration_ms,
                'cost_credits' => $result['execution']->cost_credits,
                'error_message' => $result['execution']->error_message,
                'completed_at' => now(),
            ]);

            if ($result['output'] === null) {
                throw new \RuntimeException("Step failed: {$result['execution']->error_message}");
            }
        } catch (\Throwable $e) {
            Log::error("ExecutePlaybookStepJob: exception caught", [
                'step_id' => $this->stepId,
                'exception' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            // Ensure step is marked as failed (may have already been updated above)
            if ($step->isRunning()) {
                $step->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'completed_at' => now(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure (called by framework after all retries exhausted or on timeout).
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error("ExecutePlaybookStepJob: job failed permanently", [
            'step_id' => $this->stepId,
            'exception' => $exception?->getMessage(),
        ]);

        $step = PlaybookStep::find($this->stepId);

        if ($step && ($step->isPending() || $step->isRunning())) {
            $step->update([
                'status' => 'failed',
                'error_message' => 'Job failed: ' . ($exception?->getMessage() ?? 'Unknown error'),
                'completed_at' => now(),
            ]);
        }
    }

    /**
     * Resolve step input from input_mapping configuration.
     * Supports references like:
     *   "steps.{order}.output.{field}"     — legacy flat playbook reference
     *   "node:{uuid}.output.{field}"       — workflow graph node reference
     *   "experiment.{field}"               — experiment-level data
     */
    private function resolveInput(PlaybookStep $step, Experiment $experiment): array
    {
        $mapping = $step->input_mapping;

        if (empty($mapping)) {
            // For workflow-driven steps, build input from experiment thesis + node prompt + previous outputs
            if ($step->workflow_node_id) {
                return $this->buildWorkflowStepInput($step, $experiment);
            }

            // Default: use experiment constraints as input
            return $experiment->constraints ?? [];
        }

        $resolved = [];

        foreach ($mapping as $key => $source) {
            if (is_string($source) && str_starts_with($source, 'node:')) {
                // Workflow graph node reference: node:{uuid}.output.{path}
                $parts = explode('.', $source, 3);
                $nodeId = str_replace('node:', '', $parts[0]);
                $path = $parts[2] ?? null;

                $nodeStep = PlaybookStep::where('experiment_id', $experiment->id)
                    ->where('workflow_node_id', $nodeId)
                    ->first();

                if ($nodeStep && is_array($nodeStep->output)) {
                    $resolved[$key] = $path ? data_get($nodeStep->output, $path) : $nodeStep->output;
                } else {
                    $resolved[$key] = null;
                }
            } elseif (is_string($source) && str_starts_with($source, 'steps.')) {
                // Legacy flat playbook reference: steps.{order}.output.{path}
                $parts = explode('.', $source, 4);
                $stepOrder = (int) ($parts[1] ?? 0);
                $previousStep = PlaybookStep::where('experiment_id', $experiment->id)
                    ->where('order', $stepOrder)
                    ->first();

                if ($previousStep && is_array($previousStep->output)) {
                    $path = $parts[3] ?? null;
                    $resolved[$key] = $path ? data_get($previousStep->output, $path) : $previousStep->output;
                } else {
                    $resolved[$key] = null;
                }
            } elseif (is_string($source) && str_starts_with($source, 'experiment.')) {
                $field = substr($source, 11);
                $resolved[$key] = data_get($experiment, $field);
            } else {
                $resolved[$key] = $source;
            }
        }

        return $resolved;
    }

    /**
     * Build input for a workflow-driven playbook step.
     *
     * Uses the experiment thesis as goal, the workflow node's prompt as task,
     * and collects outputs from completed predecessor steps.
     */
    private function buildWorkflowStepInput(PlaybookStep $step, Experiment $experiment): array
    {
        $input = [
            'goal' => $experiment->thesis ?? $experiment->title,
        ];

        // Get the workflow node's config for this step's prompt/instructions
        $workflowGraph = $experiment->constraints['workflow_graph'] ?? [];
        $nodes = collect($workflowGraph['nodes'] ?? []);
        $nodeData = $nodes->firstWhere('id', $step->workflow_node_id);

        if ($nodeData) {
            $nodeConfig = is_string($nodeData['config'] ?? null)
                ? json_decode($nodeData['config'], true)
                : ($nodeData['config'] ?? []);

            if (! empty($nodeConfig['prompt'])) {
                $input['task'] = $nodeConfig['prompt'];
            }
        }

        // Collect outputs from completed predecessor steps
        $completedSteps = PlaybookStep::where('experiment_id', $experiment->id)
            ->where('status', 'completed')
            ->where('order', '<', $step->order)
            ->orderBy('order')
            ->get();

        if ($completedSteps->isNotEmpty()) {
            $context = [];
            foreach ($completedSteps as $prev) {
                if (is_array($prev->output)) {
                    $context[] = $prev->output;
                }
            }

            if (! empty($context)) {
                $input['context'] = $context;
            }
        }

        return $input;
    }
}
