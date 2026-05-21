<?php

namespace App\Domain\Agent\Models;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Skill\Models\Skill;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $agent_id
 * @property string|null $experiment_id
 * @property string|null $team_id
 * @property string $status
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $output
 * @property array<int|string, mixed>|null $skills_executed
 * @property array<int|string, mixed>|null $tools_used
 * @property int $tool_calls_count
 * @property int $llm_steps_count
 * @property int|null $duration_ms
 * @property int $cost_credits
 * @property float|null $quality_score
 * @property array<string, mixed>|null $quality_details
 * @property string|null $evaluation_method
 * @property string|null $judge_model
 * @property string|null $error_message
 * @property string|null $extracted_skill_id
 * @property array<string, mixed>|null $workspace_contract
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AgentExecution extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'agent_id',
        'experiment_id',
        'team_id',
        'status',
        'input',
        'output',
        'skills_executed',
        'tools_used',
        'tool_calls_count',
        'llm_steps_count',
        'duration_ms',
        'cost_credits',
        'quality_score',
        'quality_details',
        'evaluation_method',
        'judge_model',
        'error_message',
        'extracted_skill_id',
        'workspace_contract',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'skills_executed' => 'array',
            'tools_used' => 'array',
            'tool_calls_count' => 'integer',
            'llm_steps_count' => 'integer',
            'duration_ms' => 'integer',
            'cost_credits' => 'integer',
            'quality_score' => 'float',
            'quality_details' => 'array',
            'workspace_contract' => 'array',
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

    public function extractedSkill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'extracted_skill_id');
    }
}
