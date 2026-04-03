<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Actions\ProcessAgentHandoffAction;
use App\Domain\Experiment\Enums\CheckpointMode;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Services\CheckpointManager;
use App\Domain\Experiment\Services\StepOutputBroadcaster;
use App\Domain\Experiment\Services\WorkflowSnapshotRecorder;
use App\Domain\Skill\Actions\ExecuteGuardrailAction;
use App\Domain\Skill\Actions\ExecuteSkillAction;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Domain\Workflow\Models\WorkflowNodeEvent;
use App\Domain\Workflow\Services\WorkflowEventRecorder;
use App\Jobs\Middleware\CheckBudgetAvailable;
use App\Jobs\Middleware\CheckKillSwitch;
use App\Jobs\Middleware\PerAgentSerialExecution;
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

    public int $tries = 5;          // Must match or exceed Horizon supervisor-ai-calls tries

    public int $timeout = 1200;     // 20 min — must be LONGER than HTTP timeout (~960s) but shorter than retry_after (1900s)

    public int $maxExceptions = 5;  // Allow up to 5 exceptions before giving up

    /** @var array<int> */
    public array $backoff = [10, 30, 60, 120];  // Progressive: 10s, 30s, 1min, 2min

    public function __construct(
        public readonly string $stepId,
        public readonly string $experimentId,
        public readonly ?string $teamId = null,
        /** Optional input overrides merged into the resolved input (e.g. clarification_answer) */
        public readonly array $inputOverrides = [],
    ) {
        $this->onQueue('ai-calls');
    }

    public function middleware(): array
    {
        return [
            new CheckKillSwitch,
            new CheckBudgetAvailable,
            new TenantRateLimit('experiments', 30),
            new PerAgentSerialExecution,
        ];
    }

    public function getAgentId(): ?string
    {
        return PlaybookStep::find($this->stepId)?->agent_id;
    }

    public function handle(ExecuteAgentAction $executeAgent, ExecuteSkillAction $executeSkill): void
    {
        $checkpointManager = app(CheckpointManager::class);

        Log::info('ExecutePlaybookStepJob: starting', [
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

        // Skip human task steps — they're handled by the approval flow
        if ($step->isWaitingHuman()) {
            return;
        }

        // If step is "running", check for checkpoint data to resume
        if ($step->isRunning()) {
            if ($step->hasCheckpoint()) {
                Log::info('ExecutePlaybookStepJob: resuming from checkpoint', [
                    'step_id' => $this->stepId,
                    'attempt' => $this->attempts(),
                ]);
                // Reset to pending so the execution flow below picks it up
                $step->update(['status' => 'pending']);
            } else {
                Log::warning("ExecutePlaybookStepJob: step found in 'running' state without checkpoint, failing it", [
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
        }

        if (! $step->isPending()) {
            return;
        }

        $experiment = Experiment::withoutGlobalScopes()->find($this->experimentId);

        if (! $experiment) {
            return;
        }

        // Resolve checkpoint durability mode from workflow config
        $checkpointMode = CheckpointMode::tryFrom(
            $experiment->constraints['workflow_graph']['checkpoint_mode'] ?? 'sync',
        ) ?? CheckpointMode::Sync;

        // Check idempotency — if we already have a result for this key, use it
        $idempotencyKey = $checkpointManager->generateIdempotencyKey(
            $this->experimentId,
            $this->stepId,
            $step->loop_count ?? 0,
        );

        $cachedResult = $checkpointManager->getIdempotentResult($idempotencyKey);
        if ($cachedResult) {
            Log::info('ExecutePlaybookStepJob: idempotency hit, using cached result', [
                'step_id' => $this->stepId,
                'idempotency_key' => $idempotencyKey,
            ]);

            $step->update([
                'status' => 'completed',
                'output' => $cachedResult,
                'idempotency_key' => $idempotencyKey,
                'completed_at' => now(),
            ]);

            return;
        }

        // Write initial checkpoint and start heartbeat
        $checkpointManager->writeCheckpoint($this->stepId, [
            'phase' => 'started',
            'attempt' => $this->attempts(),
            'started_at' => now()->toIso8601String(),
        ], mode: $checkpointMode);

        $step->update([
            'status' => 'running',
            'started_at' => now(),
            'idempotency_key' => $idempotencyKey,
        ]);

        // Broadcast real-time node status: running
        app(StepOutputBroadcaster::class)->broadcastNodeStatus($step, 'running');

        // Record time-travel snapshot: step started
        app(WorkflowSnapshotRecorder::class)->record(
            experiment: $experiment,
            eventType: 'step_started',
            step: $step,
        );

        $stopHeartbeat = $checkpointManager->startHeartbeat($this->stepId);

        // Record execution chain event (best-effort, never blocks execution)
        $chainEvent = null;
        if ($step->workflow_node_id) {
            try {
                $wfNode = WorkflowNode::find($step->workflow_node_id);
                $chainEvent = app(WorkflowEventRecorder::class)->recordStarted(
                    step: $step,
                    nodeType: $wfNode?->type->value ?? 'agent',
                    nodeLabel: $wfNode?->label ?? '',
                    rootEventId: $step->root_event_id,
                );
            } catch (\Throwable) {
                // Non-blocking — event tracing failures must not stop execution
            }
        }

        try {
            $input = $this->resolveInput($step, $experiment);

            // Run guardrail check if a guardrail skill is configured on this workflow node
            if ($step->workflow_node_id) {
                $workflowNode = WorkflowNode::find($step->workflow_node_id);

                if ($workflowNode?->guardrail_skill_id) {
                    $guardrailSkill = $workflowNode->guardrailSkill;

                    if ($guardrailSkill) {
                        $guardrailResult = app(ExecuteGuardrailAction::class)->execute(
                            guardrailSkill: $guardrailSkill,
                            input: $input,
                            teamId: $experiment->team_id,
                            userId: $experiment->user_id,
                            experimentId: $experiment->id,
                        );

                        $step->update(['guardrail_result' => $guardrailResult]);

                        if (! $guardrailResult['safe'] && in_array($guardrailResult['risk_level'], ['high', 'critical'])) {
                            $stopHeartbeat();
                            $step->update([
                                'status' => 'failed',
                                'error_message' => "Guardrail blocked: {$guardrailResult['reason']}",
                                'completed_at' => now(),
                            ]);

                            throw new \RuntimeException("Guardrail blocked execution: {$guardrailResult['reason']}");
                        }
                    }
                }
            }

            // Skill-only steps (e.g. boruna_step nodes) — no agent required
            if ($step->skill_id && ! $step->agent_id) {
                $skill = $step->skill;

                if (! $skill) {
                    throw new \RuntimeException("Skill {$step->skill_id} not found for step {$this->stepId}.");
                }

                Log::info('ExecutePlaybookStepJob: calling executeSkill (skill-only step)', [
                    'step_id' => $this->stepId,
                    'skill' => $skill->name,
                    'input_keys' => array_keys($input),
                    'attempt' => $this->attempts(),
                ]);

                $result = $executeSkill->execute(
                    skill: $skill,
                    input: $input,
                    teamId: $experiment->team_id,
                    userId: $experiment->user_id,
                    agentId: null,
                    experimentId: $experiment->id,
                );
            } else {
                Log::info('ExecutePlaybookStepJob: calling executeAgent', [
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
            }

            // Stop heartbeat
            if ($stopHeartbeat) {
                $stopHeartbeat();
            }

            // Detect clarification interrupt — agent paused to wait for human input.
            // Reset step to pending so it will be re-executed after clarification is provided.
            if (($result['output']['awaiting_clarification'] ?? false) === true) {
                Log::info('ExecutePlaybookStepJob: clarification required, resetting step to pending', [
                    'step_id' => $this->stepId,
                    'question' => $result['output']['question'] ?? '',
                ]);

                $checkpointManager->clearCheckpoint($this->stepId);
                $step->update([
                    'status' => 'pending',
                    'started_at' => null,
                    'idempotency_key' => null,
                ]);

                return;
            }

            // Detect handoff directive — agent wants to transfer control to another agent
            if (is_array($result['output']) && isset($result['output']['_handoff'])) {
                Log::info('ExecutePlaybookStepJob: handoff detected', [
                    'step_id' => $this->stepId,
                    'target' => $result['output']['_handoff']['target_agent_id'] ?? 'unknown',
                ]);

                $checkpointManager->clearCheckpoint($this->stepId);
                app(ProcessAgentHandoffAction::class)->execute(
                    experiment: $experiment,
                    step: $step,
                    handoffDirective: $result['output']['_handoff'],
                );

                return;
            }

            Log::info('ExecutePlaybookStepJob: completed', [
                'step_id' => $this->stepId,
                'success' => $result['output'] !== null,
                'duration_ms' => $result['execution']->duration_ms ?? 0,
            ]);

            $stepStatus = $result['output'] !== null ? 'completed' : 'failed';

            $step->update([
                'status' => $stepStatus,
                'output' => $result['output'],
                'duration_ms' => $result['execution']->duration_ms,
                'cost_credits' => $result['execution']->cost_credits,
                'error_message' => $result['execution']->error_message,
                'completed_at' => now(),
            ]);

            // Broadcast real-time node status: completed or failed
            $outputPreview = '';
            if (is_array($result['output'])) {
                $outputText = $result['output']['result'] ?? json_encode($result['output']);
                $outputPreview = mb_substr((string) $outputText, 0, 200);
            }

            app(StepOutputBroadcaster::class)->broadcastNodeStatus($step, $stepStatus, [
                'duration_ms' => $result['execution']->duration_ms ?? 0,
                'cost' => (float) ($result['execution']->cost_credits ?? 0),
                'output_preview' => $outputPreview,
            ]);

            // Record time-travel snapshot: step completed or failed
            app(WorkflowSnapshotRecorder::class)->record(
                experiment: $experiment,
                eventType: $result['output'] !== null ? 'step_completed' : 'step_failed',
                step: $step,
                input: $input ?? null,
                output: $result['output'],
                metadata: [
                    'duration_ms' => $result['execution']->duration_ms ?? 0,
                    'cost_credits' => $result['execution']->cost_credits ?? 0,
                ],
            );

            // Cache result for idempotency
            if ($result['output'] !== null) {
                $checkpointManager->storeIdempotentResult($idempotencyKey, $result['output']);
                $checkpointManager->clearCheckpoint($this->stepId);
            }

            // Record completion event (best-effort)
            if ($chainEvent instanceof WorkflowNodeEvent) {
                try {
                    $durationMs = $result['execution']->duration_ms ?? 0;
                    $success = $result['output'] !== null;
                    $recorder = app(WorkflowEventRecorder::class);
                    if ($success) {
                        $outputSummary = is_array($result['output'])
                            ? implode(', ', array_keys($result['output']))
                            : null;
                        $recorder->recordCompleted($chainEvent, $durationMs, $outputSummary);
                    } else {
                        $recorder->recordFailed($chainEvent, $result['execution']->error_message ?? 'Unknown error', $durationMs);
                    }
                } catch (\Throwable) {
                    // Non-blocking
                }
            }

            if ($result['output'] === null) {
                throw new \RuntimeException("Step failed: {$result['execution']->error_message}");
            }
        } catch (\Throwable $e) {
            // Stop heartbeat on failure
            if ($stopHeartbeat) {
                $stopHeartbeat();
            }

            Log::error('ExecutePlaybookStepJob: exception caught', [
                'step_id' => $this->stepId,
                'exception' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            // Record time-travel snapshot: step failed (best-effort)
            app(WorkflowSnapshotRecorder::class)->record(
                experiment: $experiment,
                eventType: 'step_failed',
                step: $step,
                metadata: ['error' => $e->getMessage()],
            );

            // Write failure checkpoint for recovery (always sync — must persist on failure)
            $checkpointManager->flushPendingCheckpoints();
            $checkpointManager->writeCheckpoint($this->stepId, [
                'phase' => 'failed',
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
                'failed_at' => now()->toIso8601String(),
            ]);

            // Record failure event (best-effort)
            if ($chainEvent instanceof WorkflowNodeEvent) {
                try {
                    app(WorkflowEventRecorder::class)->recordFailed($chainEvent, $e->getMessage());
                } catch (\Throwable) {
                    // Non-blocking
                }
            }

            // If retries remain, reset step to pending and let the framework retry.
            // Without this, the step would be marked 'failed' and the early guard
            // at the top of handle() would skip all subsequent retry attempts.
            $hasRetriesLeft = $this->attempts() < $this->tries;

            if ($hasRetriesLeft) {
                // Reset step to pending so the retry attempt can re-execute it
                $step->fresh()?->update(['status' => 'pending', 'worker_id' => null]);

                Log::warning('ExecutePlaybookStepJob: execution failed — releasing for retry', [
                    'step_id' => $this->stepId,
                    'attempt' => $this->attempts(),
                    'max_tries' => $this->tries,
                    'error' => $e->getMessage(),
                    'retry_in' => ($this->backoff[$this->attempts() - 1] ?? 120).'s',
                ]);

                throw $e; // Let framework handle retry with backoff
            }

            // Final attempt exhausted — mark step as permanently failed
            if ($step->fresh()?->isRunning()) {
                $step->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'completed_at' => now(),
                ]);
            }

            // Broadcast real-time node status: failed
            app(StepOutputBroadcaster::class)->broadcastNodeStatus($step, 'failed');

            throw $e;
        }
    }

    /**
     * Handle a job failure (called by framework after all retries exhausted or on timeout).
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('ExecutePlaybookStepJob: job failed permanently', [
            'step_id' => $this->stepId,
            'exception' => $exception?->getMessage(),
        ]);

        $step = PlaybookStep::find($this->stepId);

        if ($step && ($step->isPending() || $step->isRunning())) {
            $step->update([
                'status' => 'failed',
                'error_message' => 'Job failed: '.($exception?->getMessage() ?? 'Unknown error'),
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

        if (empty($mapping) || is_string($mapping)) {
            // For workflow-driven steps, build input from experiment thesis + node prompt + previous outputs
            if ($step->workflow_node_id) {
                return array_merge($this->buildWorkflowStepInput($step, $experiment), $this->inputOverrides);
            }

            // Default: use experiment constraints as input
            return array_merge($experiment->constraints ?? [], $this->inputOverrides);
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
                // Restrict to safe scalar fields — prevent relationship traversal
                // to sensitive attributes (team.credential_key, user.password, etc.)
                static $allowedExperimentFields = [
                    'id', 'title', 'thesis', 'status', 'track', 'input_data',
                    'constraints', 'metadata', 'created_at', 'updated_at',
                ];
                $topLevel = explode('.', $field)[0];
                if (! in_array($topLevel, $allowedExperimentFields, true)) {
                    $resolved[$key] = null;
                } else {
                    $resolved[$key] = data_get($experiment, $field);
                }
            } else {
                $resolved[$key] = $source;
            }
        }

        return array_merge($resolved, $this->inputOverrides);
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

        // Inject signal payload fields (subject, body, from, etc.) for signal-triggered workflows
        $signalPayload = $experiment->meta['signal_payload'] ?? null;
        if (is_array($signalPayload)) {
            foreach ($signalPayload as $key => $value) {
                if (is_string($value) && ! isset($input[$key])) {
                    $input[$key] = $value;
                }
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

                    // Merge predecessor output fields into input (don't overwrite existing keys)
                    foreach ($prev->output as $k => $v) {
                        if (! isset($input[$k])) {
                            $input[$k] = $v;
                        }
                    }
                }
            }

            if (! empty($context)) {
                $input['context'] = $context;
            }
        }

        return $input;
    }
}
