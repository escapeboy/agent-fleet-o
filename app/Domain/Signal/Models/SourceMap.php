<?php

namespace App\Domain\Signal\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SourceMap extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'project',
        'release',
        'map_data',
    ];

    protected $casts = [
        'map_data' => 'array',
    ];
}
