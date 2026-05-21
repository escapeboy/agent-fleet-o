<?php

namespace App\Domain\Skill\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $skill_id
 * @property string|null $agent_id
 * @property string|null $experiment_id
 * @property string|null $team_id
 * @property string $status
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $output
 * @property int|null $duration_ms
 * @property int $cost_credits
 * @property float|null $quality_score
 * @property array<string, mixed>|null $quality_details
 * @property string|null $evaluation_method
 * @property string|null $judge_model
 * @property string|null $error_message
 * @property array<string, mixed>|null $error_metadata
 * @property float|null $confidence_score
 * @property string|null $consensus_level
 * @property array<string, mixed>|null $peer_reviews
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SkillExecution extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'skill_id',
        'agent_id',
        'experiment_id',
        'team_id',
        'status',
        'input',
        'output',
        'duration_ms',
        'cost_credits',
        'quality_score',
        'quality_details',
        'evaluation_method',
        'judge_model',
        'error_message',
        'error_metadata',
        'confidence_score',
        'consensus_level',
        'peer_reviews',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'duration_ms' => 'integer',
            'cost_credits' => 'integer',
            'quality_score' => 'float',
            'quality_details' => 'array',
            'confidence_score' => 'float',
            'peer_reviews' => 'array',
            'error_metadata' => 'array',
        ];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }
}
