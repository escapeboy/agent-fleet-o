<?php

namespace App\Domain\Evaluation\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Evaluation\Enums\EvaluationStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationRun extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'dataset_id',
        'experiment_id',
        'agent_id',
        'workflow_id',
        'status',
        'criteria',
        'aggregate_scores',
        'total_cost_credits',
        'judge_model',
        'judge_prompt',
        'summary',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EvaluationStatus::class,
            'criteria' => 'array',
            'aggregate_scores' => 'array',
            'summary' => 'array',
            'total_cost_credits' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(EvaluationDataset::class, 'dataset_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(EvaluationScore::class, 'run_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(EvaluationRunResult::class, 'run_id');
    }
}
