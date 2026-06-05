<?php

namespace App\Domain\Evaluation\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A point-in-time score snapshot from the production eval monitor (#5).
 */
class EvaluationMonitorSnapshot extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'dataset_id',
        'run_id',
        'avg_score',
        'pass_rate',
        'active_count',
        'deferred_passed',
        'sampled_count',
    ];

    protected function casts(): array
    {
        return [
            'avg_score' => 'float',
            'pass_rate' => 'float',
            'active_count' => 'integer',
            'deferred_passed' => 'integer',
            'sampled_count' => 'integer',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(EvaluationDataset::class, 'dataset_id');
    }
}
