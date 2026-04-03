<?php

namespace App\Domain\Skill\Jobs;

use App\Domain\Skill\Services\AutoSkillCreationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ClusterExperimentsForSkillsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(AutoSkillCreationService $service): void
    {
        $created = $service->run(dryRun: false);

        Log::info('ClusterExperimentsForSkillsJob: Completed', ['skills_created' => $created]);
    }
}
