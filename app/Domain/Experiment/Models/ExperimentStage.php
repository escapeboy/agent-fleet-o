<?php

namespace App\Domain\Experiment\Models;

use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Shared\Traits\BelongsToTeam;
use Database\Factories\Domain\Experiment\ExperimentStageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExperimentStage extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'experiment_id',
        'stage',
        'iteration',
        'status',
        'input_snapshot',
        'output_snapshot',
        'retry_count',
        'duration_ms',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'stage' => StageType::class,
            'status' => StageStatus::class,
            'input_snapshot' => 'array',
            'output_snapshot' => 'array',
            'iteration' => 'integer',
            'retry_count' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function newFactory()
    {
        return ExperimentStageFactory::new();
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }
}
