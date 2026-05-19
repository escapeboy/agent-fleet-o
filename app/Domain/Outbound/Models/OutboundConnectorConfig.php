<?php

namespace App\Domain\Outbound\Models;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Infrastructure\Encryption\Casts\TeamEncryptedArray;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $team_id
 * @property string $channel
 * @property array<string, mixed>|null $credentials
 * @property bool $is_active
 * @property Carbon|null $last_tested_at
 * @property string|null $last_test_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $masked_key
 */
class OutboundConnectorConfig extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'channel',
        'credentials',
        'is_active',
        'last_tested_at',
        'last_test_status',
    ];

    protected $hidden = [
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => TeamEncryptedArray::class,
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get a masked preview of the primary credential value.
     */
    public function getMaskedKeyAttribute(): ?string
    {
        $creds = $this->credentials;
        if (! $creds) {
            return null;
        }

        $value = $creds['bot_token']
            ?? $creds['webhook_url']
            ?? $creds['access_token']
            ?? $creds['api_key']
            ?? $creds['password']
            ?? $creds['secret']
            ?? $creds['default_url']
            ?? null;

        if (! $value || strlen($value) < 8) {
            return $value ? str_repeat('*', strlen($value)) : null;
        }

        return '****...'.substr($value, -4);
    }
}
