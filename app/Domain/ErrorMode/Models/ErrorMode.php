<?php

namespace App\Domain\ErrorMode\Models;

use App\Domain\ErrorMode\Enums\ErrorModeLever;
use App\Domain\ErrorMode\Enums\ErrorModeStatus;
use App\Domain\Evaluation\Models\EvaluationCase;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named, clustered production failure mode (Diagnose stage artifact).
 * Each mode points at a lever and accumulates occurrences over time.
 *
 * @property ErrorModeLever $lever
 * @property ErrorModeStatus $status
 * @property array<int, string> $example_trace_ids
 */
class ErrorMode extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'slug',
        'name',
        'description',
        'lever',
        'status',
        'occurrence_count',
        'first_seen_at',
        'last_seen_at',
        'example_trace_ids',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'lever' => ErrorModeLever::class,
            'status' => ErrorModeStatus::class,
            'occurrence_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'example_trace_ids' => 'array',
            'metadata' => 'array',
        ];
    }

    public function evaluationCases(): HasMany
    {
        return $this->hasMany(EvaluationCase::class, 'error_mode_id');
    }
}
