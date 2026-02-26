<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactIdentity extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'display_name',
        'email',
        'phone',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function channels(): HasMany
    {
        return $this->hasMany(ContactChannel::class);
    }
}
