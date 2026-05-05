<?php

namespace App\Domain\Memory\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Enums\MemoryCategory;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Enums\MemoryVisibility;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Memory extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'agent_id',
        'project_id',
        'content',
        'embedding',
        'embedding_at_creation',
        'metadata',
        'source_type',
        'source_id',
        'confidence',
        'importance',
        'last_accessed_at',
        'retrieval_count',
        'visibility',
        'content_hash',
        'tags',
        'tier',
        'category',
        'topic',
        'proposed_by',
        'source_url',
        'boost',
        'chunk_context',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'tags' => 'array',
            'confidence' => 'float',
            'importance' => 'float',
            'last_accessed_at' => 'datetime',
            'retrieval_count' => 'integer',
            'boost' => 'integer',
            'visibility' => MemoryVisibility::class,
            'tier' => MemoryTier::class,
            'category' => MemoryCategory::class,
        ];
    }

    /**
     * Effective importance combines base importance with retrieval reinforcement.
     * Formula: min(importance + ln(1 + retrieval_count) * 0.15, 1.0)
     */
    protected function effectiveImportance(): Attribute
    {
        return Attribute::get(fn () => min(
            ($this->importance ?? 0.5) + log(1 + ($this->retrieval_count ?? 0)) * 0.15,
            1.0,
        ));
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
