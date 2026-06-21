<?php

namespace App\Domain\ProductGraph\Models;

use App\Domain\ProductGraph\Enums\EdgeType;
use App\Domain\Shared\Traits\BelongsToTeam;
use Database\Factories\Domain\ProductGraph\ProductEdgeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $team_id
 * @property string $source_node_id
 * @property string $target_node_id
 * @property EdgeType $edge_type
 * @property string|null $description
 * @property array<string, mixed> $metadata
 * @property-read ProductNode|null $source
 * @property-read ProductNode|null $target
 */
class ProductEdge extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'source_node_id',
        'target_node_id',
        'edge_type',
        'description',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'edge_type' => EdgeType::class,
            'metadata' => 'array',
        ];
    }

    protected static function newFactory(): ProductEdgeFactory
    {
        return ProductEdgeFactory::new();
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ProductNode::class, 'source_node_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(ProductNode::class, 'target_node_id');
    }
}
