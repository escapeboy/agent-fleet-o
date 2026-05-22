<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeGraph\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Signal\Models\Entity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KgEdge extends Model
{
    use BelongsToTeam, HasUuids;

    /**
     * Node type constants — 'entity' (Signal Entity record) or 'chunk' (Memory record acting as a graph node).
     */
    public const NODE_TYPE_ENTITY = 'entity';

    public const NODE_TYPE_CHUNK = 'chunk';

    /**
     * Edge type constants.
     *
     * - relates_to : entity ↔ entity semantic relationship (default)
     * - contains   : chunk (memory) → entity — source provenance
     * - co_occurs  : entity ↔ entity within the same memory
     * - similar    : memory ↔ memory by embedding proximity (lazy)
     */
    public const EDGE_TYPE_RELATES_TO = 'relates_to';

    public const EDGE_TYPE_CONTAINS = 'contains';

    public const EDGE_TYPE_CO_OCCURS = 'co_occurs';

    public const EDGE_TYPE_SIMILAR = 'similar';

    protected $table = 'kg_edges';

    protected $fillable = [
        'team_id',
        'source_entity_id',
        'source_node_type',
        'target_entity_id',
        'target_node_type',
        'edge_type',
        'relation_type',
        'fact',
        'fact_embedding',
        'valid_at',
        'invalid_at',
        'expired_at',
        'episode_id',
        'attributes',
    ];

    protected function casts(): array
    {
        return [
            'fact_embedding' => 'array',
            'valid_at' => 'datetime',
            'invalid_at' => 'datetime',
            'expired_at' => 'datetime',
            'attributes' => 'array',
        ];
    }

    /** Scope to only currently valid facts (invalid_at IS NULL). */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('invalid_at');
    }

    /** Scope to edges where both endpoints are entity-type nodes (default graph). */
    public function scopeEntities(Builder $query): Builder
    {
        return $query->where('source_node_type', self::NODE_TYPE_ENTITY)
            ->where('target_node_type', self::NODE_TYPE_ENTITY);
    }

    /** Scope by edge type. */
    public function scopeEdgeType(Builder $query, string $type): Builder
    {
        return $query->where('edge_type', $type);
    }

    /** Scope to semantic entity ↔ entity relationships. */
    public function scopeRelatesTo(Builder $query): Builder
    {
        return $query->where('edge_type', self::EDGE_TYPE_RELATES_TO);
    }

    /** Scope to memory-chunk → entity provenance edges. */
    public function scopeContains(Builder $query): Builder
    {
        return $query->where('edge_type', self::EDGE_TYPE_CONTAINS);
    }

    public function sourceEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'source_entity_id');
    }

    public function targetEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'target_entity_id');
    }
}
