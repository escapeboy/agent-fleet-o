<?php

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

    protected $table = 'kg_edges';

    protected $fillable = [
        'team_id',
        'source_entity_id',
        'target_entity_id',
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

    public function sourceEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'source_entity_id');
    }

    public function targetEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'target_entity_id');
    }
}
