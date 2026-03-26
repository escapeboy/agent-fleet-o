<?php

declare(strict_types=1);

namespace App\Domain\GitRepository\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a directed edge in the code graph between two CodeElements.
 * Edge types: 'calls' (function/method call), 'imports' (module import),
 * 'inherits' (class inheritance). Used by HKUDS CodeGraphTraversal service.
 */
class CodeEdge extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'git_repository_id',
        'source_id',
        'target_id',
        'edge_type',
    ];

    public function gitRepository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(CodeElement::class, 'source_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(CodeElement::class, 'target_id');
    }
}
