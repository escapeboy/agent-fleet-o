<?php

namespace App\Models;

use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\NotificationPreferencesService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use LaravelWebauthn\WebauthnAuthenticatable;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasPushSubscriptions, HasUuids, Notifiable, WebauthnAuthenticatable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'current_team_id',
        'theme',
        'notification_preferences',
        'changelog_seen_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'notification_preferences' => 'array',
            'changelog_seen_at' => 'datetime',
        ];
    }

    public function getPreferences(): array
    {
        $defaults = NotificationPreferencesService::defaults();
        $saved = $this->notification_preferences ?? [];

        return array_merge($defaults, $saved);
    }

    public function prefersChannel(string $type, string $channel): bool
    {
        return in_array($channel, $this->getPreferences()[$type] ?? []);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function ownedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'owner_id');
    }

    /** @return BelongsTo<Team, $this> */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    public function switchTeam(Team $team): void
    {
        if (! $this->belongsToTeam($team)) {
            throw new \InvalidArgumentException('User does not belong to this team.');
        }

        $this->update(['current_team_id' => $team->id]);
        $this->setRelation('currentTeam', $team);
    }

    public function belongsToTeam(Team $team): bool
    {
        return $this->teams()->where('team_id', $team->id)->exists();
    }

    public function teamRole(Team $team): ?TeamRole
    {
        $pivot = $this->teams()->where('team_id', $team->id)->first()?->pivot;

        return $pivot ? TeamRole::from($pivot->role) : null;
    }

    public function isTeamOwner(?Team $team = null): bool
    {
        $team ??= $this->currentTeam;

        return $team && $this->teamRole($team) === TeamRole::Owner;
    }

    public function hasTeamRole(TeamRole $role, ?Team $team = null): bool
    {
        $team ??= $this->currentTeam;

        return $team && $this->teamRole($team) === $role;
    }
}
