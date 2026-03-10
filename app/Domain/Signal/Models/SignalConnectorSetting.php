<?php

namespace App\Domain\Signal\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Infrastructure\Encryption\Casts\TeamEncryptedString;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-team signal connector configuration.
 *
 * One row per (team_id, driver). Stores the HMAC signing secret used to verify
 * inbound webhooks at the per-team endpoint POST /api/signals/{driver}/{team_id}.
 *
 * Secrets are encrypted with XSalsa20-Poly1305 under the team's DEK.
 * Secret rotation keeps the previous secret valid for 1 hour (grace period).
 */
class SignalConnectorSetting extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'driver',
        'webhook_secret',
        'previous_webhook_secret',
        'secret_rotated_at',
        'last_signal_at',
        'signal_count',
        'is_active',
        'metadata',
    ];

    protected $hidden = [
        'webhook_secret',
        'previous_webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'webhook_secret' => TeamEncryptedString::class,
            'previous_webhook_secret' => TeamEncryptedString::class,
            'secret_rotated_at' => 'datetime',
            'last_signal_at' => 'datetime',
            'signal_count' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * The unique per-team webhook URL for this connector.
     * External services should be configured to POST to this URL.
     */
    public function webhookUrl(): string
    {
        return url("/api/signals/{$this->driver}/{$this->team_id}");
    }

    /**
     * Whether the previous secret is still valid (within the 1-hour grace period).
     */
    public function isPreviousSecretValid(): bool
    {
        return $this->previous_webhook_secret !== null
            && $this->secret_rotated_at !== null
            && $this->secret_rotated_at->addHour()->isFuture();
    }

    /**
     * Return a masked hint of the secret (first 8 chars + "...").
     * Safe to display in the UI without revealing the full secret.
     */
    public function secretHint(): string
    {
        $secret = $this->webhook_secret;

        if (! $secret) {
            return '';
        }

        return substr($secret, 0, 8).'...';
    }

    /**
     * Scope to active settings for the current team.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
