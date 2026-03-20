<?php

namespace App\Domain\Evaluation\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationCase extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'dataset_id',
        'team_id',
        'input',
        'expected_output',
        'context',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(EvaluationDataset::class, 'dataset_id');
    }
}
