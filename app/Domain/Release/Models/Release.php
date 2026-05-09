<?php

declare(strict_types=1);

namespace App\Domain\Release\Models;

use App\Domain\Release\Enums\ReleaseStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\Artifact;
use App\Models\User;
use Database\Factories\Domain\Release\ReleaseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Release extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'user_id',
        'name',
        'slug',
        'version',
        'notes',
        'status',
        'share_token',
        'metadata',
        'published_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReleaseStatus::class,
            'metadata' => 'array',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function newFactory()
    {
        return ReleaseFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function artifacts(): BelongsToMany
    {
        return $this->belongsToMany(Artifact::class, 'release_artifacts')
            ->withPivot(['artifact_version', 'sort_order'])
            ->orderByPivot('sort_order')
            ->withTimestamps();
    }

    public function releaseArtifacts(): HasMany
    {
        return $this->hasMany(ReleaseArtifact::class);
    }

    public function isPublished(): bool
    {
        return $this->status === ReleaseStatus::Published;
    }

    public function isArchived(): bool
    {
        return $this->status === ReleaseStatus::Archived;
    }

    public function isDraft(): bool
    {
        return $this->status === ReleaseStatus::Draft;
    }
}
