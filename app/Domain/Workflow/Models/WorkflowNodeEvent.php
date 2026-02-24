<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowNodeEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'experiment_id',
        'playbook_step_id',
        'workflow_node_id',
        'node_type',
        'node_label',
        'event_type',
        'root_event_id',
        'parent_event_id',
        'input_summary',
        'output_summary',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
        ];
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(PlaybookStep::class, 'playbook_step_id');
    }

    public function rootEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_event_id');
    }

    public function parentEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_event_id');
    }

    public function isStarted(): bool
    {
        return $this->event_type === 'started';
    }

    public function isCompleted(): bool
    {
        return $this->event_type === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->event_type === 'failed';
    }
}
