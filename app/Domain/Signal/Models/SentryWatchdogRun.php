<?php

namespace App\Domain\Signal\Models;

use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One execution of the Sentry Watchdog batch for a single Sentry project.
 * Provides run history and the digest source.
 *
 * @property string $id
 * @property string $integration_id
 * @property string $team_id
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int $signals_triaged
 * @property int $prs_opened
 * @property int $investigate_only
 * @property int $critical_count
 * @property string|null $digest_summary
 */
class SentryWatchdogRun extends Model
{
    use BelongsToTeam, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'integration_id',
        'team_id',
        'started_at',
        'finished_at',
        'signals_triaged',
        'prs_opened',
        'investigate_only',
        'critical_count',
        'digest_summary',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'signals_triaged' => 'integer',
            'prs_opened' => 'integer',
            'investigate_only' => 'integer',
            'critical_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Integration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }
}
