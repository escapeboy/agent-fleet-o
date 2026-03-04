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
            // INTENTIONAL: `encrypted:array` (APP_KEY) is used here instead of TeamEncryptedString.
            // The KMS credentials are the access keys needed to UNWRAP the team's DEK from the
            // external KMS provider (AWS KMS / GCP KMS / Azure Key Vault). Encrypting them with
            // TeamEncryptedString would require the team DEK, which itself requires calling KMS —
            // creating a circular dependency that would make decryption impossible.
            // DO NOT "upgrade" this to TeamEncryptedString or TeamEncryptedArray.
            'credentials' => 'encrypted:array',
            'status' => KmsConfigStatus::class,
            'dek_wrapped_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}
