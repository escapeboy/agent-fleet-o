<?php

namespace App\Domain\Broadcast\Models;

use App\Domain\Broadcast\Enums\BroadcastRecipientStatus;
use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Traits\BelongsToTeam;
use Database\Factories\Domain\Broadcast\BroadcastRecipientFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery attempt of a Broadcast to a single contact.
 *
 * `message_id` holds the email provider's id, so Resend delivery webhooks can
 * reconcile bounces back onto the recipient.
 */
class BroadcastRecipient extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'broadcast_id',
        'contact_identity_id',
        'email',
        'status',
        'message_id',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BroadcastRecipientStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    protected static function newFactory(): BroadcastRecipientFactory
    {
        return BroadcastRecipientFactory::new();
    }

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    public function contactIdentity(): BelongsTo
    {
        return $this->belongsTo(ContactIdentity::class);
    }
}
