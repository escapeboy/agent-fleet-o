<?php

namespace App\Domain\Memory\Jobs;

use App\Domain\Memory\Actions\ContextualChunkEnricherAction;
use App\Domain\Memory\Models\Memory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

class ContextualChunkEnricherJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public readonly string $memoryId,
        public readonly string $documentContext,
    ) {
        $this->onQueue('default');
    }

    public function handle(ContextualChunkEnricherAction $action): void
    {
        $memory = Memory::withoutGlobalScopes()->find($this->memoryId);

        if (! $memory) {
            return;
        }

        $action->execute($memory, $this->documentContext);
    }
}
