<?php

namespace App\Domain\Credential\Models;

use App\Domain\Credential\Enums\CredentialSource;
use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Infrastructure\Encryption\Casts\TeamEncryptedArray;
use Database\Factories\Domain\Credential\CredentialFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property CredentialType $credential_type
 * @property CredentialStatus $status
 * @property CredentialSource|null $creator_source
 * @property array<string, mixed>|null $secret_data
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $last_rotated_at
 */
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
        'creator_source',
        'creator_type',
        'creator_id',
        'allowed_domains',
    ];

    protected $hidden = ['secret_data'];

    protected function casts(): array
    {
        return [
            'credential_type' => CredentialType::class,
            'status' => CredentialStatus::class,
            'creator_source' => CredentialSource::class,
            'secret_data' => TeamEncryptedArray::class,
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_rotated_at' => 'datetime',
            'allowed_domains' => 'array',
        ];
    }

    public function creator(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * All historical snapshots of this credential's secret_data, newest first.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(CredentialVersion::class)->orderByDesc('version_number');
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

    /**
     * Returns true when the given domain is permitted by the allowlist.
     * An empty/null allowlist means all domains are allowed.
     * Supports wildcard entries like *.example.com.
     */
    public function isDomainAllowed(string $domain): bool
    {
        $allowlist = $this->allowed_domains;

        if (empty($allowlist)) {
            return true;
        }

        // parse_url needs a scheme to correctly identify host vs path
        $host = parse_url($domain, PHP_URL_HOST)
            ?? parse_url('https://'.$domain, PHP_URL_HOST)
            ?? $domain;

        foreach ($allowlist as $entry) {
            $entry = (string) $entry;

            if (str_starts_with($entry, '*.')) {
                $suffix = substr($entry, 2);
                if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                    return true;
                }
            } elseif ($host === $entry) {
                return true;
            }
        }

        return false;
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(CredentialAccessLog::class)->orderByDesc('created_at');
    }
}
