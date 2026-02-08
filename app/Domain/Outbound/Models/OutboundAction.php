<?php

namespace App\Domain\Outbound\Models;

use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboundAction extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'outbound_proposal_id',
        'status',
        'external_id',
        'response',
        'retry_count',
        'idempotency_key',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OutboundActionStatus::class,
            'response' => 'array',
            'retry_count' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function outboundProposal(): BelongsTo
    {
        return $this->belongsTo(OutboundProposal::class);
    }
}
