<?php

namespace App\Domain\Testing\Models;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Testing\Enums\TestStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'test_suite_id',
        'experiment_id',
        'status',
        'results',
        'score',
        'agent_feedback',
        'started_at',
        'completed_at',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'status' => TestStatus::class,
            'results' => 'array',
            'score' => 'float',
            'agent_feedback' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    public function testSuite(): BelongsTo
    {
        return $this->belongsTo(TestSuite::class);
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function isPassed(): bool
    {
        return $this->status === TestStatus::Passed;
    }

    public function recordResult(TestStatus $status, array $results, float $score, ?array $feedback = null): void
    {
        $this->update([
            'status' => $status,
            'results' => $results,
            'score' => $score,
            'agent_feedback' => $feedback,
            'completed_at' => now(),
            'duration_ms' => $this->started_at
                ? now()->diffInMilliseconds($this->started_at)
                : null,
        ]);
    }
}
