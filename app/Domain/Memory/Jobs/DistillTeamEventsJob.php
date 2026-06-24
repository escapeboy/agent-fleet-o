<?php

namespace App\Domain\Memory\Jobs;

use App\Domain\Memory\Actions\DistillTeamEventsAction;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\TeamAiAccessChecker;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Distils one team's recent event stream into a durable memory digest.
 * Dispatched per-team by the `memory:distill-events` command.
 */
class DistillTeamEventsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public readonly string $teamId,
        public readonly ?string $sinceIso = null,
    ) {
        $this->onQueue('ai-calls');
    }

    public function handle(DistillTeamEventsAction $action, TeamAiAccessChecker $aiAccess): void
    {
        // Pre-flight gate: skip teams with no usable AI path (BYOK key, plan
        // entitlement, local agent, or bridge). This autonomous job runs for
        // every team, so without the gate it guarantees a mid-run failure for
        // BYOK teams that never configured a key. (#875/#848)
        $team = Team::find($this->teamId);
        if ($team === null || ! $aiAccess->canUseAi($team)) {
            Log::debug('DistillTeamEventsJob: skipped — team has no usable AI path', [
                'team_id' => $this->teamId,
            ]);

            return;
        }

        $action->execute(
            teamId: $this->teamId,
            since: $this->sinceIso !== null ? Carbon::parse($this->sinceIso) : null,
        );
    }
}
