<?php

namespace App\Domain\Signal\Models;

use App\Domain\KnowledgeGraph\Models\KgEdge;
use App\Domain\Shared\Traits\BelongsToTeam;
use Database\Factories\Domain\Signal\EntityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entity extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected static function newFactory(): EntityFactory
    {
        return EntityFactory::new();
    }

    protected $fillable = [
        'team_id',
        'type',
        'name',
        'canonical_name',
        'metadata',
        'mention_count',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'mention_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function signals(): BelongsToMany
    {
        return $this->belongsToMany(Signal::class, 'entity_signal')
            ->withPivot(['context', 'confidence'])
            ->withTimestamps();
    }

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(KgEdge::class, 'source_entity_id');
    }

    public function incomingEdges(): HasMany
    {
        return $this->hasMany(KgEdge::class, 'target_entity_id');
    }

    public function currentFacts(): HasMany
    {
        return $this->hasMany(KgEdge::class, 'source_entity_id')->whereNull('invalid_at');
    }
}
