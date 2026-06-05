<?php

namespace App\Domain\Evaluation\Models;

use App\Domain\ErrorMode\Models\ErrorMode;
use App\Domain\Evaluation\Enums\EvaluationCaseStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property EvaluationCaseStatus $status
 */
class EvaluationCase extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'dataset_id',
        'team_id',
        'input',
        'expected_output',
        'status',
        'source',
        'error_mode',
        'error_mode_id',
        'context',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => EvaluationCaseStatus::class,
            'metadata' => 'array',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(EvaluationDataset::class, 'dataset_id');
    }

    public function errorMode(): BelongsTo
    {
        return $this->belongsTo(ErrorMode::class, 'error_mode_id');
    }
}
