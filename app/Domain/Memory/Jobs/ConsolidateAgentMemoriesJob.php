<?php

namespace App\Domain\Memory\Jobs;

use App\Domain\Memory\Actions\ConsolidateMemoriesAction;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ConsolidateAgentMemoriesJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public readonly string $agentId,
        public readonly string $teamId,
    ) {
        $this->onQueue('ai-calls');
    }

    public function handle(ConsolidateMemoriesAction $action): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $action->execute($this->agentId, $this->teamId);
    }
}
