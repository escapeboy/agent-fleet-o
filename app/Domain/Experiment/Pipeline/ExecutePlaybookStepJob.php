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

    public int $tries = 2;
    public int $timeout = 300;

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
            new CheckKillSwitch(),
            new CheckBudgetAvailable(),
            new TenantRateLimit('experiments', 30),
        ];
    }

    public function handle(ExecuteAgentAction $executeAgent): void
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

        $step->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            // Resolve input from mapping
            $input = $this->resolveInput($step, $experiment);

            $result = $executeAgent->execute(
                agent: $step->agent,
                input: $input,
                teamId: $experiment->team_id,
                userId: $experiment->user_id,
                experimentId: $experiment->id,
            );

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
            $step->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
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
}
