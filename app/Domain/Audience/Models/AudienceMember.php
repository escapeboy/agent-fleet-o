<?php

namespace App\Domain\Audience\Models;

use App\Domain\Audience\Enums\AudienceMemberStatus;
use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Traits\BelongsToTeam;
use Database\Factories\Domain\Audience\AudienceMemberFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership of a ContactIdentity in an Audience, with subscription state.
 */
class AudienceMember extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'audience_id',
        'contact_identity_id',
        'status',
        'subscribed_at',
        'unsubscribed_at',
        'unsubscribe_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => AudienceMemberStatus::class,
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AudienceMemberFactory
    {
        return AudienceMemberFactory::new();
    }

    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }

    public function contactIdentity(): BelongsTo
    {
        return $this->belongsTo(ContactIdentity::class);
    }
}
