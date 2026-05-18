<?php

namespace App\Domain\Project\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A restorable point-in-time capture of a Project's configuration.
 * Kanwas-inspired sprint — workspace version history.
 *
 * @property string $id
 * @property string $team_id
 * @property string $project_id
 * @property string|null $created_by
 * @property string $label
 * @property array $snapshot
 * @property Carbon|null $restored_at
 */
class ProjectSnapshot extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'project_id',
        'created_by',
        'label',
        'snapshot',
        'restored_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'restored_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
