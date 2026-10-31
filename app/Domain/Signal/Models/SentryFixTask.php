<?php

namespace App\Domain\Signal\Models;

use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Signal\Enums\FixTier;
use App\Domain\Signal\Enums\SentryFixTaskState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Sentry issue queued for an external fix worker (executor = external).
 *
 * @property string $id
 * @property string $team_id
 * @property string|null $integration_id
 * @property string|null $signal_id
 * @property string $sentry_issue_id
 * @property string|null $project
 * @property string $repository
 * @property string $title
 * @property string|null $culprit
 * @property string|null $sentry_url
 * @property string|null $root_cause
 * @property list<string>|null $suspect_files
 * @property float $confidence
 * @property bool $is_critical
 * @property FixTier|null $tier
 * @property SentryFixTaskState $state
 * @property string|null $claimed_by
 * @property Carbon|null $lease_expires_at
 * @property string|null $pr_url
 * @property int $attempts
 * @property list<array{at: string, state: string, message: string}>|null $events
 */
class SentryFixTask extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'integration_id',
        'signal_id',
        'sentry_issue_id',
        'project',
        'repository',
        'title',
        'culprit',
        'sentry_url',
        'root_cause',
        'suspect_files',
        'confidence',
        'is_critical',
        'tier',
        'state',
        'claimed_by',
        'lease_expires_at',
        'pr_url',
        'attempts',
        'events',
    ];

    protected function casts(): array
    {
        return [
            'suspect_files' => 'array',
            'confidence' => 'float',
            'is_critical' => 'boolean',
            'tier' => FixTier::class,
            'state' => SentryFixTaskState::class,
            'lease_expires_at' => 'datetime',
            'attempts' => 'integer',
            'events' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Integration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * @return BelongsTo<Signal, $this>
     */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    public function appendEvent(SentryFixTaskState $state, string $message): void
    {
        $events = $this->events ?? [];
        $events[] = ['at' => now()->toIso8601String(), 'state' => $state->value, 'message' => $message];
        $this->events = $events;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'integration_id' => $this->integration_id,
            'signal_id' => $this->signal_id,
            'sentry_issue_id' => $this->sentry_issue_id,
            'project' => $this->project,
            'repository' => $this->repository,
            'title' => $this->title,
            'culprit' => $this->culprit,
            'sentry_url' => $this->sentry_url,
            'root_cause' => $this->root_cause,
            'suspect_files' => $this->suspect_files ?? [],
            'confidence' => $this->confidence,
            'is_critical' => $this->is_critical,
            'tier' => $this->tier?->value,
            'state' => $this->state->value,
            'claimed_by' => $this->claimed_by,
            'lease_expires_at' => $this->lease_expires_at?->toIso8601String(),
            'pr_url' => $this->pr_url,
            'attempts' => $this->attempts,
            'events' => $this->events ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
