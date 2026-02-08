<?php

namespace App\Domain\Outbound\Models;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Enums\OutboundChannel;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OutboundProposal extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'experiment_id',
        'channel',
        'target',
        'content',
        'risk_score',
        'status',
        'batch_index',
        'batch_id',
    ];

    protected function casts(): array
    {
        return [
            'channel' => OutboundChannel::class,
            'status' => OutboundProposalStatus::class,
            'target' => 'array',
            'content' => 'array',
            'risk_score' => 'float',
            'batch_index' => 'integer',
        ];
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function outboundActions(): HasMany
    {
        return $this->hasMany(OutboundAction::class);
    }

    public function approvalRequest(): HasOne
    {
        return $this->hasOne(ApprovalRequest::class);
    }
}
