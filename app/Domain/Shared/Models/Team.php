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

/**
 * @property array<string, mixed>|null $settings
 * @property string|null $plan
 * @property array<string, mixed>|null $custom_limits
 */
class Team extends Model
{
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::creating(function (self $team) {
            if (! $team->credential_key) {
                $team->credential_key = CredentialEncryption::generateKey();
            }
            if (! $team->widget_public_key) {
                $team->widget_public_key = 'wk_'.\Illuminate\Support\Str::random(40);
            }
        });

        static::deleting(function (self $team) {
            if ($team->is_platform) {
                throw new \RuntimeException('The platform team cannot be deleted.');
            }
        });
    }

    protected $fillable = [
        'name',
        'slug',
        'owner_id',
        'is_platform',
        'claude_code_vps_allowed',
        'assistant_ui_artifacts_allowed',
        'settings',
        'credential_key',
        'default_email_theme_id',
        'allowed_models',
        'widget_public_key',
    ];

    protected $hidden = [
        'credential_key',
    ];

    protected function casts(): array
    {
        return [
            'is_platform' => 'boolean',
            'claude_code_vps_allowed' => 'boolean',
            'assistant_ui_artifacts_allowed' => 'boolean',
            'settings' => 'array',
            'allowed_models' => 'array',
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
