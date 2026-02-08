<?php

namespace App\Domain\Metrics\Models;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricAggregation extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'experiment_id',
        'metric_type',
        'period',
        'period_start',
        'sum_value',
        'count',
        'avg_value',
        'breakdown',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'sum_value' => 'float',
            'count' => 'integer',
            'avg_value' => 'float',
            'breakdown' => 'array',
        ];
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }
}
