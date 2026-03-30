<?php

namespace App\Domain\Skill\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Skill\Enums\IterationOutcome;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkillIterationLog extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'benchmark_id',
        'skill_id',
        'team_id',
        'version_id',
        'iteration_number',
        'metric_value',
        'baseline_at_iteration',
        'complexity_delta',
        'effective_improvement',
        'outcome',
        'diff_summary',
        'crash_message',
        'duration_ms',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => IterationOutcome::class,
            'metric_value' => 'float',
            'baseline_at_iteration' => 'float',
            'complexity_delta' => 'integer',
            'effective_improvement' => 'float',
            'duration_ms' => 'integer',
            'iteration_number' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function benchmark(): BelongsTo
    {
        return $this->belongsTo(SkillBenchmark::class, 'benchmark_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SkillVersion::class, 'version_id');
    }
}
