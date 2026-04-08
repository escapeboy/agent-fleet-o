<?php

namespace App\Domain\Evaluation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationRunResult extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'row_id',
        'actual_output',
        'score',
        'judge_reasoning',
        'execution_time_ms',
        'error',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'execution_time_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EvaluationRun::class, 'run_id');
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(EvaluationDatasetRow::class, 'row_id');
    }
}
