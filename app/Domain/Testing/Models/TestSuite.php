<?php

namespace App\Domain\Testing\Models;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Testing\Enums\TestStrategy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestSuite extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'project_id',
        'name',
        'test_agent_count',
        'test_strategy',
        'assertion_rules',
        'quality_threshold',
        'last_run_at',
        'pass_rate',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'test_strategy' => TestStrategy::class,
            'assertion_rules' => 'array',
            'quality_threshold' => 'float',
            'test_agent_count' => 'integer',
            'pass_rate' => 'float',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function testRuns(): HasMany
    {
        return $this->hasMany(TestRun::class);
    }

    public function latestRun(): ?TestRun
    {
        /** @var TestRun|null */
        return $this->testRuns()->latest()->first();
    }
}
