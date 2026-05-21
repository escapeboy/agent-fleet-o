<?php

namespace App\Domain\Experiment\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Enums\ExperimentTaskStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string $experiment_id
 * @property string|null $stage
 * @property string|null $batch_id
 * @property string $name
 * @property string|null $description
 * @property string|null $type
 * @property ExperimentTaskStatus $status
 * @property string|null $agent_id
 * @property string|null $provider
 * @property string|null $model
 * @property array<string, mixed>|null $input_data
 * @property array<string, mixed>|null $output_data
 * @property string|null $error
 * @property int $sort_order
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int|null $duration_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
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
