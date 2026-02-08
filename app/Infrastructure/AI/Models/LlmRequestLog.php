<?php

namespace App\Infrastructure\AI\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LlmRequestLog extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'idempotency_key',
        'agent_id',
        'experiment_id',
        'experiment_stage_id',
        'provider',
        'model',
        'prompt_hash',
        'status',
        'response_body',
        'input_tokens',
        'output_tokens',
        'cost_credits',
        'latency_ms',
        'error',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_credits' => 'integer',
            'latency_ms' => 'integer',
            'completed_at' => 'datetime',
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
