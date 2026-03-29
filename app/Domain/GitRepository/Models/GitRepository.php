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
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $team_id
 * @property string|null $credential_id
 * @property string $name
 * @property string $url
 * @property GitProvider $provider
 * @property GitRepoMode $mode
 * @property string|null $default_branch
 * @property array<string, mixed>|null $config
 * @property GitRepositoryStatus $status
 * @property Carbon|null $last_ping_at
 * @property string|null $last_ping_status
 * @property string|null $indexing_status
 * @property Carbon|null $last_indexed_at
 * @property string|null $indexed_commit_sha
 */
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
        'indexing_status',
        'last_indexed_at',
        'indexed_commit_sha',
    ];

    protected function casts(): array
    {
        return [
            'provider' => GitProvider::class,
            'mode' => GitRepoMode::class,
            'status' => GitRepositoryStatus::class,
            'config' => 'array',
            'last_ping_at' => 'datetime',
            'last_indexed_at' => 'datetime',
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

    public function codeElements(): HasMany
    {
        return $this->hasMany(CodeElement::class);
    }

    public function codeEdges(): HasMany
    {
        return $this->hasMany(CodeEdge::class);
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
