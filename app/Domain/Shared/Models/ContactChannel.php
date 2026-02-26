<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactChannel extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'contact_identity_id',
        'team_id',
        'channel',
        'external_id',
        'external_username',
        'verified',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(ContactIdentity::class, 'contact_identity_id');
    }
}
