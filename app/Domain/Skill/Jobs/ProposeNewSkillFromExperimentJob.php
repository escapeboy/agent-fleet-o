<?php

namespace App\Domain\Skill\Jobs;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Skill\Actions\ProposeNewSkillFromExperimentAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProposeNewSkillFromExperimentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(
        public readonly string $experimentId,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        if (! config('skills.auto_propose.enabled', true)) {
            return;
        }

        $experiment = Experiment::withoutGlobalScopes()->find($this->experimentId);
        if (! $experiment) {
            return;
        }

        try {
            $skill = app(ProposeNewSkillFromExperimentAction::class)->execute($experiment);

            if ($skill) {
                Log::info('ProposeNewSkillFromExperimentJob: Auto-proposed skill', [
                    'experiment_id' => $experiment->id,
                    'skill_id' => $skill->id,
                    'skill_name' => $skill->name,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('ProposeNewSkillFromExperimentJob: Failed', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
