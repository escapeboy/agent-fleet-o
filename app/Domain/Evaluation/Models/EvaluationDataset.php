<?php

namespace App\Domain\Evaluation\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EvaluationDataset extends Model
{
    use BelongsToTeam, HasUuids, SoftDeletes;

    protected $fillable = [
        'team_id',
        'workflow_id',
        'name',
        'description',
        'case_count',
        'row_count',
    ];

    protected function casts(): array
    {
        return [
            'case_count' => 'integer',
            'row_count' => 'integer',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(EvaluationCase::class, 'dataset_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(EvaluationDatasetRow::class, 'dataset_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(EvaluationRun::class, 'dataset_id');
    }
}
