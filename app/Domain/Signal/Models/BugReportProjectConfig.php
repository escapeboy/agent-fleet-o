<?php

namespace App\Domain\Signal\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string $project
 * @property array<string, mixed> $config
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BugReportProjectConfig extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'project',
        'config',
    ];

    protected $casts = [
        'config' => 'array',
    ];
}
