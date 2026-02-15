<?php

namespace App\Models;

use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Artifact extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'experiment_id',
        'crew_execution_id',
        'project_run_id',
        'type',
        'name',
        'current_version',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'current_version' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function crewExecution(): BelongsTo
    {
        return $this->belongsTo(CrewExecution::class);
    }

    public function projectRun(): BelongsTo
    {
        return $this->belongsTo(ProjectRun::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ArtifactVersion::class);
    }

    public function latestVersion(): HasMany
    {
        return $this->versions()->where('version', $this->current_version);
    }
}
