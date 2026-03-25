<?php

namespace App\Domain\Workflow\Jobs;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Contracts\NodeExecutorInterface;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Executors\HttpRequestNodeExecutor;
use App\Domain\Workflow\Executors\KnowledgeRetrievalNodeExecutor;
use App\Domain\Workflow\Executors\LlmNodeExecutor;
use App\Domain\Workflow\Executors\ParameterExtractorNodeExecutor;
use App\Domain\Workflow\Executors\TemplateTransformNodeExecutor;
use App\Domain\Workflow\Executors\VariableAggregatorNodeExecutor;
use App\Domain\Workflow\Models\WorkflowNode;
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
 * Lightweight job for executing non-agent workflow nodes:
 * llm, http_request, parameter_extractor, variable_aggregator,
 * template_transform, knowledge_retrieval.
 *
 * Agent and crew nodes continue to use ExecutePlaybookStepJob.
 */
class ExecuteWorkflowNodeJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 5;

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
            new TenantRateLimit('experiments', 60),
        ];
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $step = PlaybookStep::find($this->stepId);

        if (! $step || $step->isCompleted() || $step->isFailed()) {
            return;
        }

        $experiment = Experiment::withoutGlobalScopes()->find($this->experimentId);

        if (! $experiment) {
            return;
        }

        if (! $step->workflow_node_id) {
            $step->update([
                'status' => 'failed',
                'error_message' => 'No workflow_node_id on step',
                'completed_at' => now(),
            ]);

            return;
        }

        $workflowNode = WorkflowNode::find($step->workflow_node_id);

        if (! $workflowNode) {
            $step->update([
                'status' => 'failed',
                'error_message' => 'Workflow node not found',
                'completed_at' => now(),
            ]);

            return;
        }

        $step->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $started = now();

        try {
            $executor = $this->resolveExecutor($workflowNode->type);
            $output = $executor->execute($workflowNode, $step, $experiment);

            $step->update([
                'status' => 'completed',
                'output' => $output,
                'duration_ms' => (int) $started->diffInMilliseconds(now()),
                'completed_at' => now(),
            ]);

            Log::info('ExecuteWorkflowNodeJob: completed', [
                'step_id' => $this->stepId,
                'node_type' => $workflowNode->type->value,
            ]);
        } catch (\Throwable $e) {
            Log::error('ExecuteWorkflowNodeJob: failed', [
                'step_id' => $this->stepId,
                'node_type' => $workflowNode->type->value,
                'error' => $e->getMessage(),
            ]);

            $step->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'duration_ms' => (int) $started->diffInMilliseconds(now()),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $step = PlaybookStep::find($this->stepId);

        if ($step && ($step->isPending() || $step->isRunning())) {
            $step->update([
                'status' => 'failed',
                'error_message' => 'Job failed: '.($exception->getMessage()),
                'completed_at' => now(),
            ]);
        }
    }

    private function resolveExecutor(WorkflowNodeType $type): NodeExecutorInterface
    {
        return match ($type) {
            WorkflowNodeType::Llm => app(LlmNodeExecutor::class),
            WorkflowNodeType::HttpRequest => app(HttpRequestNodeExecutor::class),
            WorkflowNodeType::ParameterExtractor => app(ParameterExtractorNodeExecutor::class),
            WorkflowNodeType::VariableAggregator => app(VariableAggregatorNodeExecutor::class),
            WorkflowNodeType::TemplateTransform => app(TemplateTransformNodeExecutor::class),
            WorkflowNodeType::KnowledgeRetrieval => app(KnowledgeRetrievalNodeExecutor::class),
            default => throw new \InvalidArgumentException("No executor for node type: {$type->value}"),
        };
    }
}
