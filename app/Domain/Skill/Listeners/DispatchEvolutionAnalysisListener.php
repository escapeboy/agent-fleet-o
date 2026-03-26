<?php

namespace App\Domain\Skill\Listeners;

use App\Domain\Agent\Events\AgentExecuted;
use App\Domain\Skill\Jobs\AnalyzeExecutionForEvolutionJob;

/**
 * Dispatches AnalyzeExecutionForEvolutionJob after an agent execution completes.
 *
 * Only dispatches when the execution has skills_executed populated and autonomous
 * evolution is enabled in config. The job itself performs the LLM analysis and
 * creates EvolutionProposal records as needed.
 */
class DispatchEvolutionAnalysisListener
{
    public function handle(AgentExecuted $event): void
    {
        if (! config('skills.autonomous_evolution.enabled', true)) {
            return;
        }

        $execution = $event->execution;

        $skillsExecuted = $execution->skills_executed ?? [];
        if (empty($skillsExecuted)) {
            return;
        }

        AnalyzeExecutionForEvolutionJob::dispatch($execution->id);
    }
}
