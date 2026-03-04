<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Enums\KmsConfigStatus;
use App\Domain\Shared\Enums\KmsProvider;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TeamKmsConfig extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'provider',
        'credentials',
        'wrapped_dek',
        'key_identifier',
        'external_id',
        'status',
        'dek_wrapped_at',
        'last_tested_at',
        'last_used_at',
        'estimated_monthly_calls',
    ];

    protected $hidden = [
        'credentials',
        'wrapped_dek',
    ];

    protected function casts(): array
    {
        return [
            'provider' => KmsProvider::class,
            'credentials' => 'encrypted:array',
            'status' => KmsConfigStatus::class,
            'dek_wrapped_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}
