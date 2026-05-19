<?php

namespace App\Domain\Audience\Models;

use App\Domain\Audience\Enums\AudienceMemberStatus;
use App\Domain\Broadcast\Models\Broadcast;
use App\Domain\Shared\Traits\BelongsToTeam;
use Database\Factories\Domain\Audience\AudienceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named, team-scoped list of contacts for outreach.
 *
 * An audience may carry a `topic` — a subscription category contacts can be
 * unsubscribed from independently. Membership and subscription state live on
 * AudienceMember.
 */
class Audience extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'name',
        'slug',
        'description',
        'topic',
    ];

    protected static function newFactory(): AudienceFactory
    {
        return AudienceFactory::new();
    }

    public function members(): HasMany
    {
        return $this->hasMany(AudienceMember::class);
    }

    public function subscribedMembers(): HasMany
    {
        return $this->hasMany(AudienceMember::class)
            ->where('status', AudienceMemberStatus::Subscribed->value);
    }

    public function broadcasts(): HasMany
    {
        return $this->hasMany(Broadcast::class);
    }
}
