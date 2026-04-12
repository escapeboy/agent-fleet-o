<?php

namespace App\Domain\Memory\Jobs;

use App\Domain\Memory\Actions\ClassifyMemoryTopicAction;
use App\Domain\Memory\Models\Memory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ClassifyMemoryTopicJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(
        private readonly string $memoryId,
    ) {
        $this->onQueue('default');
    }

    public function handle(ClassifyMemoryTopicAction $action): void
    {
        $memory = Memory::withoutGlobalScopes()->find($this->memoryId);

        if (! $memory) {
            return;
        }

        $action->execute($memory);
    }
}
