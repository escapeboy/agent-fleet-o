<?php

namespace App\Domain\Project\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectDependency extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'project_id',
        'depends_on_id',
        'team_id',
        'reference_type',
        'specific_run_id',
        'alias',
        'extract_config',
        'sort_order',
        'is_required',
    ];

    protected function casts(): array
    {
        return [
            'extract_config' => 'array',
            'sort_order' => 'integer',
            'is_required' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'depends_on_id');
    }

    public function specificRun(): BelongsTo
    {
        return $this->belongsTo(ProjectRun::class, 'specific_run_id');
    }

    public function scopeRequired(Builder $query): Builder
    {
        return $query->where('is_required', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }
}
