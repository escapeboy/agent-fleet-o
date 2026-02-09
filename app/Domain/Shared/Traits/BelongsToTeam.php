<?php

namespace App\Domain\Shared\Traits;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Scopes\TeamScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTeam
{
    public static function bootBelongsToTeam(): void
    {
        static::addGlobalScope(new TeamScope);

        static::creating(function ($model) {
            if (empty($model->team_id)) {
                $model->team_id = static::resolveTeamId();
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    protected static function resolveTeamId(): ?string
    {
        $user = auth()->user();

        if ($user?->current_team_id) {
            return $user->current_team_id;
        }

        // In console context without auth, skip auto-assignment (queue jobs set team_id explicitly)
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        return null;
    }
}
