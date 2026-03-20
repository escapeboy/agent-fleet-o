<?php

namespace App\Domain\Evaluation\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationDataset extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'name',
        'description',
        'case_count',
    ];

    protected function casts(): array
    {
        return [
            'case_count' => 'integer',
        ];
    }

    public function cases(): HasMany
    {
        return $this->hasMany(EvaluationCase::class, 'dataset_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(EvaluationRun::class, 'dataset_id');
    }
}
