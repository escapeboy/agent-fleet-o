<?php

namespace App\Domain\WorldModel\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TeamWorldModel extends Model
{
    use BelongsToTeam, HasUuids;

    protected $table = 'team_world_models';

    protected $fillable = [
        'team_id',
        'digest',
        'provider',
        'model',
        'stats',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function isStale(int $daysOld = 7): bool
    {
        if ($this->generated_at === null) {
            return true;
        }

        return $this->generated_at->lt(now()->subDays($daysOld));
    }
}
