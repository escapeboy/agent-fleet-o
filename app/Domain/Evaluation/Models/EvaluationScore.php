<?php

namespace App\Domain\Evaluation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationScore extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'case_id',
        'criterion',
        'score',
        'reasoning',
        'judge_model',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EvaluationRun::class, 'run_id');
    }

    public function evaluationCase(): BelongsTo
    {
        return $this->belongsTo(EvaluationCase::class, 'case_id');
    }
}
