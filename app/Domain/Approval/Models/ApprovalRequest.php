<?php

namespace App\Domain\Approval\Models;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Models\User;
use Database\Factories\Domain\Approval\ApprovalRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalRequest extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'experiment_id',
        'outbound_proposal_id',
        'workflow_node_id',
        'reviewed_by',
        'assigned_to',
        'status',
        'rejection_reason',
        'reviewer_notes',
        'context',
        'form_schema',
        'form_response',
        'assignment_policy',
        'expires_at',
        'sla_deadline',
        'escalation_chain',
        'escalation_level',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'context' => 'array',
            'form_schema' => 'array',
            'form_response' => 'array',
            'escalation_chain' => 'array',
            'escalation_level' => 'integer',
            'expires_at' => 'datetime',
            'sla_deadline' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function newFactory()
    {
        return ApprovalRequestFactory::new();
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function outboundProposal(): BelongsTo
    {
        return $this->belongsTo(OutboundProposal::class);
    }

    public function workflowNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowNode::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function isHumanTask(): bool
    {
        return $this->workflow_node_id !== null && ! empty($this->form_schema);
    }

    public function isSlaExpired(): bool
    {
        return $this->sla_deadline && $this->sla_deadline->isPast();
    }

    public function hasEscalationLevels(): bool
    {
        return ! empty($this->escalation_chain) && $this->escalation_level < count($this->escalation_chain);
    }
}
