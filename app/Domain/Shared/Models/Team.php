<?php

namespace App\Domain\Shared\Models;

use App\Domain\Email\Models\EmailTheme;
use App\Domain\Shared\Enums\TeamRole;
use App\Infrastructure\Encryption\CredentialEncryption;
use App\Models\User;
use Database\Factories\Domain\Shared\TeamFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::creating(function (self $team) {
            if (! $team->credential_key) {
                $team->credential_key = CredentialEncryption::generateKey();
            }
        });
    }

    protected $fillable = [
        'name',
        'slug',
        'owner_id',
        'settings',
        'credential_key',
        'default_email_theme_id',
    ];

    protected $hidden = [
        'credential_key',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'credential_key' => 'encrypted',
        ];
    }

    protected static function newFactory()
    {
        return TeamFactory::new();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function providerCredentials(): HasMany
    {
        return $this->hasMany(TeamProviderCredential::class);
    }

    public function activeCredentialFor(string $provider): ?TeamProviderCredential
    {
        return $this->providerCredentials()
            ->where('provider', $provider)
            ->where('is_active', true)
            ->first();
    }

    public function defaultEmailTheme(): BelongsTo
    {
        return $this->belongsTo(EmailTheme::class, 'default_email_theme_id');
    }

    public function emailThemes(): HasMany
    {
        return $this->hasMany(EmailTheme::class);
    }

    public function memberRole(User $user): ?TeamRole
    {
        $pivot = $this->users()->where('user_id', $user->id)->first()?->pivot;

        return $pivot ? TeamRole::from($pivot->role) : null;
    }

    /**
     * Community edition: all features are available.
     */
    public function hasFeature(string $feature): bool
    {
        return true;
    }
}
