<?php

namespace App\Domain\Approval\Models;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\User;
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
        'reviewed_by',
        'status',
        'rejection_reason',
        'reviewer_notes',
        'context',
        'expires_at',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'context' => 'array',
            'expires_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function outboundProposal(): BelongsTo
    {
        return $this->belongsTo(OutboundProposal::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
