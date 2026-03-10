<?php

namespace App\Domain\Integration\Models;

use App\Infrastructure\Encryption\Casts\TeamEncryptedString;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookRoute extends Model
{
    use HasUuids;

    protected $fillable = [
        'integration_id',
        'slug',
        'signing_secret',
        'subscribed_events',
        'is_active',
    ];

    protected $hidden = ['signing_secret'];

    protected function casts(): array
    {
        return [
            'signing_secret' => TeamEncryptedString::class,
            'subscribed_events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Resolve team_id via the parent Integration, since webhook_routes has no direct team_id column.
     * Used by TeamEncryptedString to look up the team's DEK.
     */
    public function getTeamIdAttribute(): ?string
    {
        return $this->integration?->team_id;
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
