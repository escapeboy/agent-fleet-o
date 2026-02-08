<?php

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamProviderCredential extends Model
{
    use HasUuids;

    protected $fillable = [
        'team_id',
        'provider',
        'credentials',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    protected $hidden = [
        'credentials',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
