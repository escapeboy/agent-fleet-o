<?php

namespace App\Domain\Fleet\Models;

use App\Domain\Shared\Scopes\TeamScope;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    use BelongsToTeam, HasUuids;

    protected $table = 'fleet_drivers';

    protected $fillable = [
        'team_id',
        'name',
        'license_number',
        'status',
        'telemetry_summary',
        'latest_score',
        'score_reasoning',
        'boruna_last_scored_at',
    ];

    protected function casts(): array
    {
        return [
            'telemetry_summary' => 'array',
            'latest_score' => 'float',
            'boruna_last_scored_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TeamScope);
    }
}
