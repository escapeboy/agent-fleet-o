<?php

namespace App\Domain\Simulation\Jobs;

use App\Domain\Simulation\Actions\RunSimulationAction;
use App\Domain\Simulation\Models\SimulationRun;
use App\Jobs\Middleware\TenantRateLimit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ExecuteSimulationRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $runId, public string $teamId)
    {
        $this->onQueue('ai-calls');
    }

    public function handle(RunSimulationAction $action): void
    {
        $run = SimulationRun::withoutGlobalScopes()->find($this->runId);

        if ($run === null) {
            return;
        }

        $action->execute($run);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new TenantRateLimit('ai-calls', 30),
            (new WithoutOverlapping($this->runId))->expireAfter(900),
        ];
    }
}
