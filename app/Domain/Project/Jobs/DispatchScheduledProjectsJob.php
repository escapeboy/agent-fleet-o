<?php

namespace App\Domain\Project\Jobs;

use App\Domain\Project\Services\ProjectScheduler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DispatchScheduledProjectsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct()
    {
        $this->onQueue('critical');
    }

    public function handle(ProjectScheduler $scheduler): void
    {
        $dispatched = $scheduler->evaluateDueProjects();

        if ($dispatched > 0) {
            Log::info("ProjectScheduler dispatched {$dispatched} project run(s)");
        }
    }
}
