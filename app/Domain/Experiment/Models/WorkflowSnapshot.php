<?php

namespace App\Domain\Experiment\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowSnapshot extends Model
{
    use BelongsToTeam, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'team_id',
        'experiment_id',
        'playbook_step_id',
        'workflow_node_id',
        'event_type',
        'sequence',
        'graph_state',
        'step_input',
        'step_output',
        'metadata',
        'duration_from_start_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'graph_state' => 'array',
            'step_input' => 'array',
            'step_output' => 'array',
            'metadata' => 'array',
            'sequence' => 'integer',
            'duration_from_start_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function playbookStep(): BelongsTo
    {
        return $this->belongsTo(PlaybookStep::class);
    }
}
