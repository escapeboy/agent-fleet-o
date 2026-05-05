<?php

namespace App\Domain\WorldModel\Jobs;

use App\Domain\Shared\Models\Team;
use App\Domain\WorldModel\Actions\BuildWorldModelDigestAction;
use App\Jobs\Middleware\ApplyTenantTracer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BuildWorldModelDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $teamId) {}

    public function middleware(): array
    {
        return [new ApplyTenantTracer];
    }

    public function handle(BuildWorldModelDigestAction $action): void
    {
        /** @var Team|null $team */
        $team = Team::withoutGlobalScopes()->find($this->teamId);
        if ($team === null) {
            Log::warning('BuildWorldModelDigestJob: team not found', ['team_id' => $this->teamId]);

            return;
        }

        $action->execute($team);
    }
}
