<?php

namespace App\Domain\ProductGraph\Models;

use App\Domain\ProductGraph\Enums\NodeStatus;
use App\Domain\ProductGraph\Enums\NodeType;
use App\Domain\Shared\Traits\BelongsToTeam;
use Database\Factories\Domain\ProductGraph\ProductNodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $team_id
 * @property NodeType $node_type
 * @property string $name
 * @property string $slug
 * @property NodeStatus $status
 * @property string|null $description
 * @property array<int, string> $tags
 * @property string|null $external_ref
 * @property array<string, mixed> $metadata
 * @property-read int|null $outgoing_edges_count
 * @property-read int|null $incoming_edges_count
 */
class ProductNode extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'node_type',
        'name',
        'slug',
        'status',
        'description',
        'tags',
        'external_ref',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'node_type' => NodeType::class,
            'status' => NodeStatus::class,
            'tags' => 'array',
            'metadata' => 'array',
        ];
    }

    protected static function newFactory(): ProductNodeFactory
    {
        return ProductNodeFactory::new();
    }

    public function scopeOfType(Builder $query, NodeType $type): Builder
    {
        return $query->where('node_type', $type->value);
    }

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(ProductEdge::class, 'source_node_id');
    }

    public function incomingEdges(): HasMany
    {
        return $this->hasMany(ProductEdge::class, 'target_node_id');
    }
}
