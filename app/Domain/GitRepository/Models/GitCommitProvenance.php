<?php

namespace App\Domain\GitRepository\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GitCommitProvenance extends Model
{
    use BelongsToTeam, HasUuids;

    protected $table = 'git_commit_provenance';

    protected $fillable = [
        'team_id',
        'git_repository_id',
        'commit_sha',
        'branch',
        'source',
        'experiment_id',
        'agent_id',
        'skill_execution_id',
        'trailers',
    ];

    protected function casts(): array
    {
        return [
            'trailers' => 'array',
        ];
    }
}
