<?php

namespace App\Domain\Outbound\Models;

use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string $outbound_proposal_id
 * @property OutboundActionStatus $status
 * @property string|null $external_id
 * @property array<string, mixed>|null $response
 * @property array<string, mixed>|null $error_metadata
 * @property int $retry_count
 * @property string|null $idempotency_key
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OutboundAction extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'outbound_proposal_id',
        'status',
        'external_id',
        'response',
        'error_metadata',
        'retry_count',
        'idempotency_key',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OutboundActionStatus::class,
            'response' => 'array',
            'error_metadata' => 'array',
            'retry_count' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function outboundProposal(): BelongsTo
    {
        return $this->belongsTo(OutboundProposal::class);
    }
}
