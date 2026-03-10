<?php

namespace App\Domain\Memory\Jobs;

use App\Domain\Memory\Actions\ExtractAndStoreMemoriesAction;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Background memory extraction job dispatched after every successful AgentExecution.
 *
 * Debounced via ShouldBeUniqueUntilProcessing: only one extraction job runs
 * per agent per 2-minute window, preventing LLM extraction bursts.
 */
class ExtractMemoryJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $uniqueFor = 120; // 2-minute debounce window

    public function __construct(
        public readonly string $agentId,
        public readonly string $teamId,
        public readonly string $executionId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "memory:extract:{$this->agentId}";
    }

    public function handle(ExtractAndStoreMemoriesAction $action): void
    {
        $action->execute($this->agentId, $this->teamId, $this->executionId);
    }
}
