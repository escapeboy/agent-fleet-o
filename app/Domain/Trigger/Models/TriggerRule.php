<?php

namespace App\Domain\Trigger\Models;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Trigger\Enums\TriggerRuleStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TriggerRule extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'project_id',
        'name',
        'source_type',
        'conditions',
        'input_mapping',
        'cooldown_seconds',
        'max_concurrent',
        'status',
        'last_triggered_at',
        'total_triggers',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'input_mapping' => 'array',
            'cooldown_seconds' => 'integer',
            'max_concurrent' => 'integer',
            'status' => TriggerRuleStatus::class,
            'last_triggered_at' => 'datetime',
            'total_triggers' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function projectRuns(): HasMany
    {
        return $this->hasMany(\App\Domain\Project\Models\ProjectRun::class);
    }

    public function matchesSourceType(string $sourceType): bool
    {
        return $this->source_type === '*' || $this->source_type === $sourceType;
    }
}
