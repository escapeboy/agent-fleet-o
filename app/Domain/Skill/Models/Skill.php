<?php

namespace App\Domain\Skill\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Skill\Enums\ExecutionType;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Enums\SkillType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Skill extends Model
{
    use BelongsToTeam, HasUuids, SoftDeletes;

    protected $fillable = [
        'team_id',
        'name',
        'slug',
        'description',
        'type',
        'execution_type',
        'status',
        'risk_level',
        'input_schema',
        'output_schema',
        'configuration',
        'cost_profile',
        'safety_flags',
        'current_version',
        'requires_approval',
        'system_prompt',
        'execution_count',
        'success_count',
        'avg_latency_ms',
    ];

    protected function casts(): array
    {
        return [
            'type' => SkillType::class,
            'execution_type' => ExecutionType::class,
            'status' => SkillStatus::class,
            'risk_level' => RiskLevel::class,
            'input_schema' => 'array',
            'output_schema' => 'array',
            'configuration' => 'array',
            'cost_profile' => 'array',
            'safety_flags' => 'array',
            'requires_approval' => 'boolean',
            'execution_count' => 'integer',
            'success_count' => 'integer',
            'avg_latency_ms' => 'decimal:2',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SkillVersion::class)->orderByDesc('created_at');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(SkillExecution::class)->orderByDesc('created_at');
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_skill')
            ->withPivot('priority', 'overrides')
            ->withTimestamps()
            ->orderByPivot('priority');
    }

    public function successRate(): float
    {
        if ($this->execution_count === 0) {
            return 0;
        }

        return round(($this->success_count / $this->execution_count) * 100, 1);
    }

    public function recordExecution(bool $success, int $durationMs): void
    {
        $this->increment('execution_count');

        if ($success) {
            $this->increment('success_count');
        }

        // Running average for latency
        $total = ($this->avg_latency_ms * ($this->execution_count - 1)) + $durationMs;
        $this->update(['avg_latency_ms' => $total / $this->execution_count]);
    }
}
