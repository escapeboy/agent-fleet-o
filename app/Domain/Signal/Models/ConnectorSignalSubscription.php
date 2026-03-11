<?php

namespace App\Domain\Signal\Models;

use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Infrastructure\Encryption\Casts\TeamEncryptedString;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-subscription connector record that links an Integration (OAuth/API key account)
 * to the Signal ingestion pipeline with per-source filtering.
 *
 * One Integration can have many subscriptions (e.g. one GitHub OAuth account →
 * multiple repo subscriptions). Each subscription has:
 *   - its own HMAC signing secret (used to verify inbound webhook payloads)
 *   - filter_config (per-driver: repo name, event types, label filters, etc.)
 *   - webhook_id (the provider's webhook record ID, for clean deregistration)
 *
 * Inbound webhooks arrive at POST /api/signals/subscription/{subscription_id}.
 * The secret is registered with the provider when the subscription is created
 * via RegisterSubscriptionWebhookJob.
 */
class ConnectorSignalSubscription extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'integration_id',
        'name',
        'driver',
        'filter_config',
        'is_active',
        'webhook_secret',
        'webhook_id',
        'webhook_status',
        'webhook_expires_at',
        'last_signal_at',
        'signal_count',
    ];

    protected $hidden = ['webhook_secret'];

    protected function casts(): array
    {
        return [
            'webhook_secret' => TeamEncryptedString::class,
            'filter_config' => 'array',
            'is_active' => 'boolean',
            'webhook_expires_at' => 'datetime',
            'last_signal_at' => 'datetime',
            'signal_count' => 'integer',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * Inbound webhook URL for this subscription.
     * Registered with the provider so events POST here with an HMAC signature.
     */
    public function webhookUrl(): string
    {
        return url("/api/signals/subscription/{$this->id}");
    }

    /**
     * Whether the webhook registration at the provider is healthy.
     */
    public function isWebhookRegistered(): bool
    {
        return $this->webhook_status === 'registered';
    }

    /**
     * Whether the Jira 30-day webhook TTL is about to expire (within 5 days).
     */
    public function isWebhookExpiringSoon(): bool
    {
        if (! $this->webhook_expires_at) {
            return false;
        }

        return $this->webhook_expires_at->isBefore(now()->addDays(5));
    }

    /**
     * A masked hint of the webhook secret (first 8 chars + "...").
     * Safe to display in the UI.
     */
    public function secretHint(): string
    {
        $secret = $this->webhook_secret;

        return $secret ? substr($secret, 0, 8).'...' : '';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByDriver(Builder $query, string $driver): Builder
    {
        return $query->where('driver', $driver);
    }

    public function scopeExpiringWebhooks(Builder $query): Builder
    {
        return $query->where('webhook_status', 'registered')
            ->whereNotNull('webhook_expires_at')
            ->where('webhook_expires_at', '<', now()->addDays(5));
    }
}
