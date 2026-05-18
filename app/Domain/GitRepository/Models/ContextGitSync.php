<?php

namespace App\Domain\GitRepository\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Links a team to a GitRepository for one-way sync of its accumulated context
 * (artifacts + memory) as a versioned markdown filesystem.
 *
 * Kanwas-inspired sprint — "your files, your repo". One sync per team.
 *
 * @property string $id
 * @property string $team_id
 * @property string $git_repository_id
 * @property string $branch
 * @property bool $sync_artifacts
 * @property bool $sync_memory
 * @property string $artifact_path_prefix
 * @property string $memory_path_prefix
 * @property string|null $last_pushed_sha
 * @property Carbon|null $last_pushed_at
 */
class ContextGitSync extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'git_repository_id',
        'branch',
        'sync_artifacts',
        'sync_memory',
        'artifact_path_prefix',
        'memory_path_prefix',
        'last_pushed_sha',
        'last_pushed_at',
    ];

    protected function casts(): array
    {
        return [
            'sync_artifacts' => 'boolean',
            'sync_memory' => 'boolean',
            'last_pushed_at' => 'datetime',
        ];
    }

    public function gitRepository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class);
    }
}
