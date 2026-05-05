<?php

namespace App\Domain\Workflow\Models;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Links a Workflow to a GitRepository for one-way YAML sync.
 * Build #5 (Trendshift top-5 sprint) — Kestra-inspired source-of-truth pattern.
 *
 * @property string $id
 * @property string $workflow_id
 * @property string $git_repository_id
 * @property string $team_id
 * @property string $branch
 * @property string $path_prefix
 * @property string|null $last_pushed_sha
 * @property Carbon|null $last_pushed_at
 */
class WorkflowGitSync extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'workflow_id',
        'git_repository_id',
        'team_id',
        'branch',
        'path_prefix',
        'last_pushed_sha',
        'last_pushed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_pushed_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function gitRepository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class);
    }
}
