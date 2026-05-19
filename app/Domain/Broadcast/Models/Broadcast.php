<?php

namespace App\Domain\Broadcast\Models;

use App\Domain\Audience\Models\Audience;
use App\Domain\Broadcast\Enums\BroadcastStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use Database\Factories\Domain\Broadcast\BroadcastFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A one-time mass email sent to every subscribed member of an Audience.
 *
 * Carries its own approval state (requested_by / approved_by) rather than a
 * shared ApprovalRequest — see the sprint plan for why.
 */
class Broadcast extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'audience_id',
        'name',
        'subject',
        'body',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'recipient_count',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BroadcastStatus::class,
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'recipient_count' => 'integer',
        ];
    }

    protected static function newFactory(): BroadcastFactory
    {
        return BroadcastFactory::new();
    }

    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }
}
