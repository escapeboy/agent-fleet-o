<?php

namespace App\Infrastructure\AI\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LlmRequestLog extends Model
{
    use BelongsToTeam, HasUuids, MassPrunable;

    public function prunable(): Builder
    {
        // Retain 30 days for debugging and billing reconciliation
        return static::withoutGlobalScopes()
            ->where('created_at', '<', now()->subDays(30));
    }

    protected $fillable = [
        'team_id',
        'user_id',
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
        'context_window_pct',
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
            'context_window_pct' => 'float',
            'output_tokens' => 'integer',
            'cost_credits' => 'integer',
            'latency_ms' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
