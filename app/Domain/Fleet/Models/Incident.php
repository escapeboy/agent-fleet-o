<?php

namespace App\Domain\Fleet\Models;

use App\Domain\Shared\Scopes\TeamScope;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    use BelongsToTeam, HasUuids;

    protected $table = 'fleet_incidents';

    protected $fillable = [
        'team_id',
        'title',
        'raw_text',
        'severity',
        'category',
        'regulator_reportable',
        'classification_reasoning',
    ];

    protected function casts(): array
    {
        return [
            'regulator_reportable' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TeamScope);
    }
}
