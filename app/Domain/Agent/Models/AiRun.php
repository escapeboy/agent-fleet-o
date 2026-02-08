<?php

namespace App\Domain\Agent\Models;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRun extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'agent_id',
        'experiment_id',
        'experiment_stage_id',
        'purpose',
        'provider',
        'model',
        'input_schema',
        'prompt_snapshot',
        'raw_output',
        'parsed_output',
        'schema_valid',
        'input_tokens',
        'output_tokens',
        'cost_credits',
        'latency_ms',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'prompt_snapshot' => 'array',
            'raw_output' => 'array',
            'parsed_output' => 'array',
            'schema_valid' => 'boolean',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_credits' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function experimentStage(): BelongsTo
    {
        return $this->belongsTo(ExperimentStage::class);
    }
}
