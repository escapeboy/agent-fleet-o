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
        'name',
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

    /**
     * Scope to only custom AI endpoints (provider='custom_endpoint').
     */
    public function scopeCustomEndpoints($query)
    {
        return $query->where('provider', 'custom_endpoint');
    }

    /**
     * Get masked API key for display (last 4 characters).
     */
    public function getMaskedApiKeyAttribute(): ?string
    {
        $key = $this->credentials['api_key'] ?? null;

        if (! $key || strlen($key) < 5) {
            return $key ? '****' : null;
        }

        return str_repeat('*', strlen($key) - 4).substr($key, -4);
    }
}
