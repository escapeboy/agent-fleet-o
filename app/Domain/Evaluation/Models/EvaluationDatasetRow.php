<?php

namespace App\Domain\Evaluation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationDatasetRow extends Model
{
    use HasUuids;

    protected $fillable = [
        'dataset_id',
        'input',
        'expected_output',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'metadata' => 'array',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(EvaluationDataset::class, 'dataset_id');
    }
}
