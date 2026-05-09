<?php

declare(strict_types=1);

namespace App\Domain\Release\Crypto\Models;

use App\Domain\Release\Crypto\Enums\SigningKeyStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Infrastructure\Encryption\Casts\TeamEncryptedString;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReleaseSigningKey extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'public_key',
        'secret_data',
        'status',
        'rotated_at',
        'revoked_at',
        'grace_expires_at',
    ];

    protected $hidden = [
        'secret_data',
    ];

    protected function casts(): array
    {
        return [
            'status' => SigningKeyStatus::class,
            'secret_data' => TeamEncryptedString::class,
            'rotated_at' => 'datetime',
            'revoked_at' => 'datetime',
            'grace_expires_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === SigningKeyStatus::Active;
    }

    public function isGrace(): bool
    {
        return $this->status === SigningKeyStatus::Grace;
    }

    public function isRevoked(): bool
    {
        return $this->status === SigningKeyStatus::Revoked;
    }

    public function isUsable(): bool
    {
        if ($this->isRevoked()) {
            return false;
        }

        if ($this->isGrace() && $this->grace_expires_at?->isPast()) {
            return false;
        }

        return true;
    }
}
