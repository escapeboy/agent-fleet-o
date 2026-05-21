<?php

namespace App\Domain\Approval\Models;

use App\Domain\Approval\Enums\ApprovalMode;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Chatbot\Models\ChatbotMessage;
use App\Domain\Credential\Models\Credential;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Skill\Models\WorktreeExecution;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Infrastructure\Encryption\Casts\TeamEncryptedString;
use App\Models\User;
use Database\Factories\Domain\Approval\ApprovalRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string|null $experiment_id
 * @property string|null $outbound_proposal_id
 * @property string|null $credential_id
 * @property string|null $workflow_node_id
 * @property string|null $reviewed_by
 * @property string|null $assigned_to
 * @property ApprovalStatus $status
 * @property ApprovalMode|null $mode
 * @property int|null $intervention_window_seconds
 * @property Carbon|null $auto_approved_at
 * @property string|null $rejection_reason
 * @property string|null $reviewer_notes
 * @property array<string, mixed>|null $context
 * @property array<string, mixed>|null $form_schema
 * @property array<string, mixed>|null $form_response
 * @property string|null $assignment_policy
 * @property Carbon|null $expires_at
 * @property Carbon|null $sla_deadline
 * @property list<string>|null $escalation_chain
 * @property int $escalation_level
 * @property Carbon|null $reviewed_at
 * @property string|null $callback_url
 * @property string|null $callback_secret
 * @property Carbon|null $callback_fired_at
 * @property string|null $callback_status
 * @property string|null $chatbot_message_id
 * @property string|null $edited_content
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Experiment|null $experiment
 * @property-read Credential|null $credential
 * @property-read OutboundProposal|null $outboundProposal
 * @property-read WorkflowNode|null $workflowNode
 * @property-read ChatbotMessage|null $chatbotMessage
 * @property-read User|null $reviewer
 * @property-read User|null $assignee
 */
class ApprovalRequest extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $hidden = [
        'callback_secret',
    ];

    protected $fillable = [
        'team_id',
        'experiment_id',
        'outbound_proposal_id',
        'credential_id',
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
        'callback_url',
        'callback_secret',
        'callback_fired_at',
        'callback_status',
        'chatbot_message_id',
        'edited_content',
        'mode',
        'intervention_window_seconds',
        'auto_approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'mode' => ApprovalMode::class,
            'intervention_window_seconds' => 'integer',
            'auto_approved_at' => 'datetime',
            'context' => 'array',
            'form_schema' => 'array',
            'form_response' => 'array',
            'escalation_chain' => 'array',
            'escalation_level' => 'integer',
            'expires_at' => 'datetime',
            'sla_deadline' => 'datetime',
            'reviewed_at' => 'datetime',
            'callback_secret' => TeamEncryptedString::class,
            'callback_fired_at' => 'datetime',
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

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
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

    /**
     * The WorktreeExecution that triggered this approval (CodeExecution skill).
     * Inverse of WorktreeExecution::$approval_request_id.
     */
    public function worktreeExecution(): HasOne
    {
        return $this->hasOne(WorktreeExecution::class);
    }

    public function chatbotMessage(): BelongsTo
    {
        return $this->belongsTo(ChatbotMessage::class);
    }

    public function isCredentialReview(): bool
    {
        return $this->credential_id !== null;
    }

    public function isChatbotResponse(): bool
    {
        return $this->chatbot_message_id !== null;
    }

    public function isCodeExecution(): bool
    {
        return $this->worktreeExecution()->exists();
    }

    public function isSecurityReview(): bool
    {
        return ($this->context['type'] ?? null) === 'security_review';
    }

    public function isClarification(): bool
    {
        return ($this->context['type'] ?? null) === 'clarification' && ! empty($this->form_schema);
    }

    public function isHumanTask(): bool
    {
        return ($this->workflow_node_id !== null && ! empty($this->form_schema))
            || $this->isClarification();
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
