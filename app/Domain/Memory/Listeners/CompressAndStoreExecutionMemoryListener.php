<?php

namespace App\Domain\Memory\Listeners;

use App\Domain\Agent\Events\AgentExecuted;
use App\Domain\Memory\Jobs\CompressAndStoreExecutionMemoryJob;

class CompressAndStoreExecutionMemoryListener
{
    public function handle(AgentExecuted $event): void
    {
        if (! $event->succeeded) {
            return;
        }

        if (! config('memory.auto_capture', true)) {
            return;
        }

        CompressAndStoreExecutionMemoryJob::dispatch($event->execution->id);
    }
}
