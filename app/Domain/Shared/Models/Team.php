<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Enums\TeamRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'owner_id',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
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
