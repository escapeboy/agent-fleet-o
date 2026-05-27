<?php

namespace App\Domain\Skill\Models;

use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Skill\Enums\SkillLiftRecommendation;
use App\Domain\Skill\Enums\SkillLiftStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A blind A/B evaluation of a skill's lift — the skill's LLM output WITH the skill
 * versus a baseline WITHOUT it, judged via LlmJudge. Distinct from production-telemetry
 * quality counters and the metric-gated benchmark loop.
 *
 * @property string $id
 * @property string $team_id
 * @property string $skill_id
 * @property string|null $skill_version_id
 * @property string|null $dataset_id
 * @property SkillLiftStatus $status
 * @property array<int, string>|null $criteria
 * @property float|null $with_skill_score
 * @property float|null $without_skill_score
 * @property float|null $delta
 * @property float|null $improvement_rate
 * @property SkillLiftRecommendation|null $recommendation
 * @property array<int, array<string, mixed>>|null $case_results
 * @property string|null $judge_model
 * @property int $cost_credits
 * @property string|null $error
 */
class SkillLiftEvaluation extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'skill_id',
        'skill_version_id',
        'dataset_id',
        'status',
        'criteria',
        'with_skill_score',
        'without_skill_score',
        'delta',
        'improvement_rate',
        'recommendation',
        'case_results',
        'judge_model',
        'cost_credits',
        'error',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SkillLiftStatus::class,
            'recommendation' => SkillLiftRecommendation::class,
            'criteria' => 'array',
            'case_results' => 'array',
            'with_skill_score' => 'decimal:2',
            'without_skill_score' => 'decimal:2',
            'delta' => 'decimal:2',
            'improvement_rate' => 'decimal:4',
            'cost_credits' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function skillVersion(): BelongsTo
    {
        return $this->belongsTo(SkillVersion::class, 'skill_version_id');
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(EvaluationDataset::class, 'dataset_id');
    }
}
