<?php

namespace App\Domain\Skill\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentSkillPivot;
use App\Domain\Shared\Enums\DataClassification;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Shared\Traits\HasPluginMeta;
use App\Domain\Skill\Enums\ExecutionType;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Enums\SkillType;
use Database\Factories\Domain\Skill\SkillFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Skill extends Model
{
    use BelongsToTeam, HasFactory, HasPluginMeta, HasUuids, SoftDeletes;

    protected static function newFactory()
    {
        return SkillFactory::new();
    }

    protected $fillable = [
        'team_id',
        'source_listing_id',
        'name',
        'slug',
        'description',
        'type',
        'execution_type',
        'status',
        'evaluation_enabled',
        'evaluation_sample_rate',
        'evaluation_model',
        'evaluation_criteria',
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
        'applied_count',
        'completed_count',
        'effective_count',
        'fallback_count',
        'provider_requirements',
        'meta',
        'data_classification',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
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
            'evaluation_enabled' => 'boolean',
            'evaluation_sample_rate' => 'float',
            'evaluation_criteria' => 'array',
            'execution_count' => 'integer',
            'success_count' => 'integer',
            'avg_latency_ms' => 'decimal:2',
            'applied_count' => 'integer',
            'completed_count' => 'integer',
            'effective_count' => 'integer',
            'fallback_count' => 'integer',
            'provider_requirements' => 'array',
            'data_classification' => DataClassification::class,
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

    public function embedding(): HasOne
    {
        return $this->hasOne(SkillEmbedding::class);
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_skill')
            ->using(AgentSkillPivot::class)
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

    public function getReliabilityRateAttribute(): float
    {
        if ($this->applied_count === 0) {
            return 0.0;
        }

        return round($this->completed_count / $this->applied_count, 4);
    }

    public function getQualityRateAttribute(): float
    {
        if ($this->completed_count === 0) {
            return 0.0;
        }

        return round($this->effective_count / $this->completed_count, 4);
    }

    public function getFallbackRateAttribute(): float
    {
        if ($this->applied_count === 0) {
            return 0.0;
        }

        return round($this->fallback_count / $this->applied_count, 4);
    }

    public function getHealthScoreAttribute(): float
    {
        return round(
            ($this->getReliabilityRateAttribute() * 0.4) +
            ($this->getQualityRateAttribute() * 0.4) +
            ((1 - $this->getFallbackRateAttribute()) * 0.2),
            4,
        );
    }

    public function isDegraded(): bool
    {
        return $this->applied_count >= config('skills.degradation.min_sample_size', 10)
            && (
                $this->getReliabilityRateAttribute() < config('skills.degradation.reliability_threshold', 0.6)
                || $this->getQualityRateAttribute() < config('skills.degradation.quality_threshold', 0.5)
            );
    }
}
