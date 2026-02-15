<?php

namespace App\Domain\Workflow\Models;

use Database\Factories\Domain\Workflow\WorkflowEdgeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
