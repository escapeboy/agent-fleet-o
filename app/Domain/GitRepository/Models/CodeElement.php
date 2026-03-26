<?php

declare(strict_types=1);

namespace App\Domain\GitRepository\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents an indexed code element (file, class, function, or method) extracted
 * from a git repository. Used by HKUDS code intelligence features for semantic
 * search and structural graph traversal.
 */
class CodeElement extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'git_repository_id',
        'element_type',
        'name',
        'file_path',
        'line_start',
        'line_end',
        'signature',
        'docstring',
        'content_hash',
        'indexed_at',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'line_start' => 'integer',
            'line_end' => 'integer',
            'indexed_at' => 'datetime',
        ];
    }

    public function gitRepository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class);
    }

    /** Directed edges where this element is the source (outgoing calls/imports/inherits). */
    public function codeEdgesOut(): HasMany
    {
        return $this->hasMany(CodeEdge::class, 'source_id');
    }

    /** Directed edges where this element is the target (incoming calls/imports/inherits). */
    public function codeEdgesIn(): HasMany
    {
        return $this->hasMany(CodeEdge::class, 'target_id');
    }
}
