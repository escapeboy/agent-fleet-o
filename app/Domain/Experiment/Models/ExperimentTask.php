<?php

namespace App\Domain\Experiment\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Enums\ExperimentTaskStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExperimentTask extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'experiment_id',
        'stage',
        'batch_id',
        'name',
        'description',
        'type',
        'status',
        'agent_id',
        'provider',
        'model',
        'input_data',
        'output_data',
        'error',
        'sort_order',
        'started_at',
        'completed_at',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExperimentTaskStatus::class,
            'input_data' => 'array',
            'output_data' => 'array',
            'sort_order' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
