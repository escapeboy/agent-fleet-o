<?php

namespace App\Domain\Outbound\Models;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Enums\OutboundChannel;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use Database\Factories\Domain\Outbound\OutboundProposalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $team_id
 * @property string|null $experiment_id
 * @property OutboundChannel $channel
 * @property mixed $target decoded JSON — recipient details (shape varies by channel)
 * @property mixed $content decoded JSON — message payload (shape varies by channel)
 * @property float $risk_score
 * @property OutboundProposalStatus $status
 * @property int $batch_index
 * @property string|null $batch_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Experiment|null $experiment
 */
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

    protected static function newFactory()
    {
        return OutboundProposalFactory::new();
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
