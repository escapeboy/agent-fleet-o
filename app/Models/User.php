<?php

namespace App\Models;

use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'current_team_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
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
