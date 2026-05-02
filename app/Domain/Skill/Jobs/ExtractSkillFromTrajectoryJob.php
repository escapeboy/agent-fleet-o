<?php

namespace App\Domain\Skill\Jobs;

use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Skill\Actions\ExtractSkillFromTrajectoryAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractSkillFromTrajectoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        private readonly string $executionId,
        private readonly string $executionType,
    ) {
        $this->onQueue('ai-calls');
    }

    public function handle(ExtractSkillFromTrajectoryAction $action): void
    {
        $execution = $this->executionType === 'crew'
            ? CrewExecution::withoutGlobalScopes()->find($this->executionId)
            : AgentExecution::withoutGlobalScopes()->find($this->executionId);

        if (! $execution) {
            Log::warning('ExtractSkillFromTrajectoryJob: execution not found', [
                'execution_id' => $this->executionId,
                'type' => $this->executionType,
            ]);

            return;
        }

        $action->execute($execution);
    }
}
