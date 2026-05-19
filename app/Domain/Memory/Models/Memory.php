<?php

namespace App\Domain\Memory\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Enums\MemoryBeliefStatus;
use App\Domain\Memory\Enums\MemoryBeliefType;
use App\Domain\Memory\Enums\MemoryCategory;
use App\Domain\Memory\Enums\MemoryPreferenceSubtype;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Enums\MemoryVisibility;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $team_id
 * @property string|null $agent_id
 * @property string|null $project_id
 * @property string $content
 * @property string|null $embedding pgvector(1536) — stored via raw SQL
 * @property string|null $embedding_at_creation pgvector(1536) — stored via raw SQL
 * @property array<string, mixed>|null $metadata
 * @property string $source_type
 * @property string|null $source_id
 * @property string|null $source_url
 * @property float $confidence
 * @property float $importance
 * @property Carbon|null $last_accessed_at
 * @property int $retrieval_count
 * @property MemoryVisibility $visibility
 * @property string|null $content_hash
 * @property array<int, mixed>|null $tags
 * @property MemoryTier $tier
 * @property MemoryCategory|null $category
 * @property MemoryBeliefType|null $belief_type
 * @property MemoryPreferenceSubtype|null $preference_subtype
 * @property string|null $why_it_matters
 * @property MemoryBeliefStatus $belief_status
 * @property string|null $domain
 * @property array<string, mixed>|null $rejected_alternatives
 * @property string|null $supersedes_id
 * @property bool $conflict_flag
 * @property string|null $conflict_with_id
 * @property Carbon|null $conflict_detected_at
 * @property string|null $topic
 * @property string|null $proposed_by
 * @property string|null $proposal_status
 * @property Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 * @property string|null $reviewed_by
 * @property int $boost
 * @property string|null $chunk_context
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read float $effective_importance
 * @property-read float $similarity cosine-similarity alias from selectRaw() — only set on neighbour queries
 */
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
        'belief_type',
        'preference_subtype',
        'why_it_matters',
        'belief_status',
        'domain',
        'rejected_alternatives',
        'supersedes_id',
        'conflict_flag',
        'conflict_with_id',
        'conflict_detected_at',
        'topic',
        'proposed_by',
        'proposal_status',
        'reviewed_at',
        'rejection_reason',
        'reviewed_by',
        'source_url',
        'boost',
        'chunk_context',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'tags' => 'array',
            'rejected_alternatives' => 'array',
            'conflict_flag' => 'boolean',
            'conflict_detected_at' => 'datetime',
            'confidence' => 'float',
            'importance' => 'float',
            'last_accessed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'retrieval_count' => 'integer',
            'boost' => 'integer',
            'visibility' => MemoryVisibility::class,
            'tier' => MemoryTier::class,
            'category' => MemoryCategory::class,
            'belief_type' => MemoryBeliefType::class,
            'preference_subtype' => MemoryPreferenceSubtype::class,
            'belief_status' => MemoryBeliefStatus::class,
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

    /**
     * The memory this one replaces (RoBrain temporal belief graph).
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * Memories that replace this one. Plural because a fact can be
     * superseded then re-superseded; the newest active row wins.
     *
     * @return HasMany<self, $this>
     */
    public function supersededBy(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_id');
    }

    /**
     * The memory this one was flagged as contradicting.
     */
    public function conflictsWith(): BelongsTo
    {
        return $this->belongsTo(self::class, 'conflict_with_id');
    }
}
