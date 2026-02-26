<?php

namespace App\Domain\Trigger\Actions;

use App\Domain\Project\Actions\TriggerProjectRunAction;
use App\Domain\Project\Enums\ProjectRunStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Signal\Models\Signal;
use App\Domain\Trigger\Models\TriggerRule;
use App\Domain\Trigger\Services\SignalInputMapper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExecuteTriggerRuleAction
{
    public function __construct(
        private TriggerProjectRunAction $triggerProjectRun,
        private SignalInputMapper $inputMapper,
    ) {}

    /**
     * Execute a matched trigger rule — enforcing cooldown and concurrency limits.
     * Returns the created ProjectRun, or null if skipped.
     */
    public function execute(TriggerRule $rule, Signal $signal): ?ProjectRun
    {
        if (! $rule->project_id) {
            Log::warning('ExecuteTriggerRuleAction: rule has no project_id', ['rule_id' => $rule->id]);

            return null;
        }

        /** @var Project|null $project */
        $project = Project::withoutGlobalScopes()
            ->where('team_id', $rule->team_id)
            ->find($rule->project_id);
        if (! $project) {
            return null;
        }

        // Cooldown check — atomic debounce via cache add() (works with redis/array/file)
        if ($rule->cooldown_seconds > 0) {
            $debounceKey = "trigger_debounce:{$rule->id}";
            $acquired = Cache::add($debounceKey, 1, $rule->cooldown_seconds);
            if (! $acquired) {
                Log::info('ExecuteTriggerRuleAction: skipped (cooldown active)', [
                    'rule_id' => $rule->id,
                    'cooldown_seconds' => $rule->cooldown_seconds,
                ]);

                return null;
            }
        }

        // Concurrency check — wrapped in SELECT FOR UPDATE to prevent TOCTOU race
        if ($rule->max_concurrent > 0) {
            $skipped = DB::transaction(function () use ($rule): bool {
                // Lock the project row so concurrent workers serialize at this point
                Project::withoutGlobalScopes()
                    ->where('id', $rule->project_id)
                    ->lockForUpdate()
                    ->first();

                $activeRuns = ProjectRun::withoutGlobalScopes()
                    ->where('project_id', $rule->project_id)
                    ->whereIn('status', [ProjectRunStatus::Pending->value, ProjectRunStatus::Running->value])
                    ->count();

                return $activeRuns >= $rule->max_concurrent;
            });

            if ($skipped) {
                Log::info('ExecuteTriggerRuleAction: skipped (max_concurrent reached)', [
                    'rule_id' => $rule->id,
                    'max_concurrent' => $rule->max_concurrent,
                ]);

                return null;
            }
        }

        // Map signal fields to project input_data
        $inputData = $this->inputMapper->map($rule->input_mapping, $signal);

        // Trigger the project run
        $run = $this->triggerProjectRun->execute(
            project: $project,
            trigger: 'signal',
            inputData: $inputData,
        );

        // Attach trigger metadata to the run
        $run->update([
            'trigger_rule_id' => $rule->id,
            'signal_id' => $signal->id,
        ]);

        // Update rule stats
        $rule->update([
            'last_triggered_at' => now(),
            'total_triggers' => $rule->total_triggers + 1,
        ]);

        Log::info('ExecuteTriggerRuleAction: project run triggered', [
            'rule_id' => $rule->id,
            'signal_id' => $signal->id,
            'run_id' => $run->id,
            'project_id' => $project->id,
        ]);

        return $run;
    }
}
