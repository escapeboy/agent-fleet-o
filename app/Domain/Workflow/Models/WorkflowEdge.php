<?php

namespace App\Domain\Workflow\Models;

use Database\Factories\Domain\Workflow\WorkflowEdgeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workflow_id
 * @property string $source_node_id
 * @property string $target_node_id
 * @property array<string, mixed>|null $condition
 * @property string|null $case_value
 * @property string|null $label
 * @property bool $is_default
 * @property int $sort_order
 * @property string|null $source_channel
 * @property string|null $target_channel
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workflow $workflow
 * @property-read WorkflowNode $sourceNode
 * @property-read WorkflowNode $targetNode
 */
class WorkflowEdge extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory()
    {
        return WorkflowEdgeFactory::new();
    }

    protected $fillable = [
        'workflow_id',
        'source_node_id',
        'target_node_id',
        'condition',
        'case_value',
        'label',
        'is_default',
        'sort_order',
        'source_channel',
        'target_channel',
    ];

    protected function casts(): array
    {
        return [
            'condition' => 'array',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function sourceNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowNode::class, 'source_node_id');
    }

    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowNode::class, 'target_node_id');
    }

    public function hasCondition(): bool
    {
        return ! empty($this->condition);
    }

    public function isDefault(): bool
    {
        return $this->is_default;
    }
}
