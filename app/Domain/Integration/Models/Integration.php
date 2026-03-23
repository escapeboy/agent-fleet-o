<?php

namespace App\Domain\Integration\Models;

use App\Domain\Credential\Models\Credential;
use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use Database\Factories\Domain\Integration\IntegrationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Integration extends Model
{
    use BelongsToTeam, HasFactory, HasUuids, SoftDeletes;

    protected static function newFactory(): IntegrationFactory
    {
        return IntegrationFactory::new();
    }

    protected $fillable = [
        'team_id',
        'driver',
        'name',
        'credential_id',
        'status',
        'config',
        'meta',
        'last_pinged_at',
        'last_ping_status',
        'last_ping_message',
        'error_count',
    ];

    protected function casts(): array
    {
        return [
            'status' => IntegrationStatus::class,
            'config' => 'array',
            'meta' => 'array',
            'last_pinged_at' => 'datetime',
            'error_count' => 'integer',
        ];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function webhookRoutes(): HasMany
    {
        return $this->hasMany(WebhookRoute::class);
    }

    public function isActive(): bool
    {
        /** @var IntegrationStatus $status */
        $status = $this->status;

        return $status === IntegrationStatus::Active;
    }

    public function getCredentialSecret(string $key, mixed $default = null): mixed
    {
        $credential = $this->credential;
        if (! $credential) {
            return $default;
        }

        /** @var array<string, mixed> $secretData */
        $secretData = $credential->getAttribute('secret_data') ?? [];

        return $secretData[$key] ?? $default;
    }
}
