<?php

namespace App\Domain\GitRepository\Models;

use App\Domain\Credential\Models\Credential;
use App\Domain\GitRepository\Enums\GitProvider;
use App\Domain\GitRepository\Enums\GitRepoMode;
use App\Domain\GitRepository\Enums\GitRepositoryStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GitRepository extends Model
{
    use BelongsToTeam, HasUuids, SoftDeletes;

    protected $fillable = [
        'team_id',
        'credential_id',
        'name',
        'url',
        'provider',
        'mode',
        'default_branch',
        'config',
        'status',
        'last_ping_at',
        'last_ping_status',
    ];

    protected function casts(): array
    {
        return [
            'provider' => GitProvider::class,
            'mode' => GitRepoMode::class,
            'status' => GitRepositoryStatus::class,
            'config' => 'array',
            'last_ping_at' => 'datetime',
        ];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(GitOperation::class);
    }

    public function pullRequests(): HasMany
    {
        return $this->hasMany(GitPullRequest::class);
    }

    public function isActive(): bool
    {
        return $this->status === GitRepositoryStatus::Active;
    }

    /**
     * Extract owner/repo slug from URL (e.g. "org/repo").
     */
    public function repoSlug(): ?string
    {
        if (preg_match('#(?:github\.com|gitlab\.com|bitbucket\.org)[:/](.+?)(?:\.git)?$#i', $this->url, $m)) {
            return trim($m[1], '/');
        }

        return null;
    }
}
