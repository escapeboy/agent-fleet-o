<?php

namespace App\Domain\Shared\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit log of every terms acceptance event.
 * This model deliberately does NOT use BelongsToTeam or SoftDeletes —
 * consent records must never be scoped to a team or deleted.
 */
class TermsAcceptance extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'version',
        'accepted_at',
        'ip_address',
        'user_agent',
        'acceptance_method',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
