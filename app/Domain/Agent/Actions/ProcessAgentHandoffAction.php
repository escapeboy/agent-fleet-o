<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Pipeline\ExecutePlaybookStepJob;
use App\Domain\Experiment\Services\WorkflowSnapshotRecorder;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Domain\Workflow\Services\WorkflowEventRecorder;
use Illuminate\Support\Facades\Log;

class ProcessAgentHandoffAction
{
    /**
     * Process a handoff directive from an agent to another agent.
     *
     * Validates the target, enforces depth limits, swaps the agent on the step,
     * records the event, and re-dispatches the step for the new agent.
     */
    public function execute(
        Experiment $experiment,
        PlaybookStep $step,
        array $handoffDirective,
    ): void {
        $targetAgentId = $handoffDirective['target_agent_id'] ?? null;
        $reason = $handoffDirective['reason'] ?? 'Agent handoff';
        $context = $handoffDirective['context'] ?? [];

        if (! $targetAgentId) {
            Log::warning('ProcessAgentHandoffAction: missing target_agent_id', [
                'step_id' => $step->id,
            ]);

            return;
        }

        // Validate target agent exists, belongs to same team, and is active
        $targetAgent = Agent::where('id', $targetAgentId)
            ->where('team_id', $experiment->team_id)
            ->where('status', AgentStatus::Active)
            ->first();

        if (! $targetAgent) {
            Log::warning('ProcessAgentHandoffAction: target agent not found or inactive', [
                'step_id' => $step->id,
                'target_agent_id' => $targetAgentId,
            ]);

            // Complete the step with what we have — can't hand off
            $step->update([
                'status' => 'completed',
                'output' => array_merge($context, [
                    '_handoff_failed' => true,
                    '_handoff_error' => 'Target agent not found or inactive',
                ]),
                'completed_at' => now(),
            ]);

            return;
        }

        // Check handoff depth limit
        $checkpointData = $step->checkpoint_data ?? [];
        $handoffChain = $checkpointData['handoff_chain'] ?? [];
        $maxHandoffs = $experiment->constraints['max_handoffs_per_step'] ?? 3;

        if (count($handoffChain) >= $maxHandoffs) {
            Log::info('ProcessAgentHandoffAction: handoff depth limit reached', [
                'step_id' => $step->id,
                'depth' => count($handoffChain),
                'max' => $maxHandoffs,
            ]);

            $step->update([
                'status' => 'completed',
                'output' => array_merge($context, [
                    '_handoff_limit_reached' => true,
                    '_handoff_chain' => $handoffChain,
                ]),
                'completed_at' => now(),
            ]);

            return;
        }

        // Build handoff chain
        $handoffChain[] = [
            'from_agent_id' => $step->agent_id,
            'to_agent_id' => $targetAgentId,
            'reason' => $reason,
            'timestamp' => now()->toIso8601String(),
        ];

        // Reset step for re-execution with new agent
        $step->update([
            'agent_id' => $targetAgentId,
            'status' => 'pending',
            'started_at' => null,
            'idempotency_key' => null,
            'checkpoint_data' => array_merge($checkpointData, [
                'handoff_chain' => $handoffChain,
                'original_agent_id' => $handoffChain[0]['from_agent_id'],
                'handoff_context' => $context,
            ]),
        ]);

        // Record workflow event (best-effort)
        try {
            $wfNode = $step->workflow_node_id ? WorkflowNode::find($step->workflow_node_id) : null;
            app(WorkflowEventRecorder::class)->recordEvent(
                step: $step,
                nodeType: $wfNode?->type->value ?? 'agent',
                nodeLabel: $wfNode->label ?? $targetAgent->name,
                eventType: 'agent_handoff',
                rootEventId: $step->root_event_id,
                summary: "Handoff to {$targetAgent->name}: {$reason}",
            );
        } catch (\Throwable) {
            // Non-blocking
        }

        // Record time-travel snapshot (best-effort)
        try {
            app(WorkflowSnapshotRecorder::class)->record(
                experiment: $experiment,
                eventType: 'agent_handoff',
                step: $step,
                metadata: [
                    'from_agent_id' => $handoffChain[count($handoffChain) - 1]['from_agent_id'],
                    'to_agent_id' => $targetAgentId,
                    'reason' => $reason,
                    'depth' => count($handoffChain),
                ],
            );
        } catch (\Throwable) {
            // Non-blocking
        }

        // Re-dispatch the step with handoff context as input overrides
        ExecutePlaybookStepJob::dispatch(
            stepId: $step->id,
            experimentId: $experiment->id,
            teamId: $experiment->team_id,
            inputOverrides: array_merge($context, [
                '_handoff_from' => $handoffChain[count($handoffChain) - 1]['from_agent_id'],
                '_handoff_reason' => $reason,
            ]),
        )->onQueue('ai-calls');

        Log::info('ProcessAgentHandoffAction: handoff dispatched', [
            'step_id' => $step->id,
            'from_agent' => $handoffChain[count($handoffChain) - 1]['from_agent_id'],
            'to_agent' => $targetAgentId,
            'depth' => count($handoffChain),
        ]);
    }
}
