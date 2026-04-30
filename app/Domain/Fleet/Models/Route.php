<?php

namespace App\Domain\Fleet\Models;

use App\Domain\Shared\Scopes\TeamScope;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Route extends Model
{
    use BelongsToTeam, HasUuids;

    protected $table = 'fleet_routes';

    protected $fillable = [
        'team_id',
        'name',
        'origin',
        'destination',
        'risk_score',
        'approval_status',
        'approval_decision_id',
    ];

    protected function casts(): array
    {
        return [
            'risk_score' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TeamScope);
    }
}
