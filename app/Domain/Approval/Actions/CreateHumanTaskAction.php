<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Models\WorkflowNode;

class CreateHumanTaskAction
{
    public function execute(
        Experiment $experiment,
        PlaybookStep $step,
        WorkflowNode $node,
    ): ApprovalRequest {
        $config = is_string($node->config) ? json_decode($node->config, true) : ($node->config ?? []);

        $slaHours = $config['sla_hours'] ?? 48;
        $assignmentPolicy = $config['assignment_policy'] ?? 'any_team_member';
        $escalationChain = $config['escalation_chain'] ?? null;

        $step->update(['status' => 'waiting_human']);

        return ApprovalRequest::withoutGlobalScopes()->create([
            'experiment_id' => $experiment->id,
            'team_id' => $experiment->team_id,
            'workflow_node_id' => $node->id,
            'status' => ApprovalStatus::Pending,
            'form_schema' => $config['form_schema'] ?? null,
            'assignment_policy' => $assignmentPolicy,
            'sla_deadline' => now()->addHours($slaHours),
            'escalation_chain' => $escalationChain,
            'escalation_level' => 0,
            'expires_at' => now()->addHours($slaHours * 2),
            'context' => [
                'experiment_title' => $experiment->title,
                'step_id' => $step->id,
                'node_label' => $node->label,
                'node_type' => 'human_task',
                'instructions' => $config['prompt'] ?? null,
            ],
        ]);
    }
}
