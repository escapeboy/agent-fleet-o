<?php

namespace App\Domain\Signal\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Signal\Enums\ConnectorBindingStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ConnectorBinding extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'channel',
        'external_id',
        'external_name',
        'status',
        'pairing_code',
        'pairing_code_expires_at',
        'approved_at',
        'approved_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConnectorBindingStatus::class,
            'pairing_code_expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', ConnectorBindingStatus::Pending->value);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', ConnectorBindingStatus::Approved->value);
    }

    public function scopeBlocked($query)
    {
        return $query->where('status', ConnectorBindingStatus::Blocked->value);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isApproved(): bool
    {
        return $this->status->isApproved();
    }

    public function isBlocked(): bool
    {
        return $this->status->isBlocked();
    }

    public function isPairingCodeExpired(): bool
    {
        return $this->pairing_code_expires_at !== null
            && $this->pairing_code_expires_at->isPast();
    }

    /**
     * Generate a fresh 6-character uppercase pairing code.
     */
    public static function generatePairingCode(): string
    {
        return strtoupper(Str::random(6));
    }
}
