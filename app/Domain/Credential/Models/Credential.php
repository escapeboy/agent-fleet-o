<?php

namespace App\Domain\Credential\Models;

use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Shared\Traits\BelongsToTeam;
use Database\Factories\Domain\Credential\CredentialFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Credential extends Model
{
    use BelongsToTeam, HasFactory, HasUuids, SoftDeletes;

    protected static function newFactory()
    {
        return CredentialFactory::new();
    }

    protected $fillable = [
        'team_id',
        'name',
        'slug',
        'description',
        'credential_type',
        'status',
        'secret_data',
        'metadata',
        'expires_at',
        'last_used_at',
        'last_rotated_at',
    ];

    protected $hidden = ['secret_data'];

    protected function casts(): array
    {
        return [
            'credential_type' => CredentialType::class,
            'status' => CredentialStatus::class,
            'secret_data' => 'encrypted:array',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_rotated_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->status === CredentialStatus::Active && ! $this->isExpired();
    }

    public function touchLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }
}
