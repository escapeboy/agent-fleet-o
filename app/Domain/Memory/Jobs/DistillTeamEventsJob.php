<?php

namespace App\Domain\Memory\Jobs;

use App\Domain\Memory\Actions\DistillTeamEventsAction;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

    public function handle(DistillTeamEventsAction $action): void
    {
        $action->execute(
            teamId: $this->teamId,
            since: $this->sinceIso !== null ? Carbon::parse($this->sinceIso) : null,
        );
    }
}
