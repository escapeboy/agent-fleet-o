<?php

namespace App\Domain\Crew\Actions;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Crew\Events\CrewExecuting;
use App\Domain\Crew\Jobs\ExecuteCrewJob;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Orchestration\Exceptions\CostGateExceededException;
use App\Domain\Orchestration\Services\OrchestrationCostEstimator;
use App\Domain\Orchestration\Services\OrchestrationCostGate;
use App\Domain\Shared\Models\Team;
use InvalidArgumentException;

class ExecuteCrewAction
{
    /**
     * @param  bool  $costConfirmed  Caller has acknowledged the projected fan-out
     *                               cost; bypasses the pre-flight cost gate.
     *
     * @throws CostGateExceededException
     */
    public function execute(
        Crew $crew,
        string $goal,
        string $teamId,
        ?string $experimentId = null,
        bool $costConfirmed = false,
    ): CrewExecution {
        if ($crew->status !== CrewStatus::Active) {
            throw new InvalidArgumentException('Crew must be active to execute.');
        }

        // Pre-flight fan-out cost gate (no-op unless enabled and over threshold).
        app(OrchestrationCostGate::class)->assertConfirmed(
            app(OrchestrationCostEstimator::class)->estimateCrew($crew),
            $costConfirmed,
            Team::withoutGlobalScopes()->find($teamId),
        );

        // Plugin hook: allow plugins to inspect or cancel crew execution
        $executing = new CrewExecuting($crew, ['goal' => $goal]);
        event($executing);
        if ($executing->cancel) {
            throw new InvalidArgumentException($executing->cancelReason ?? 'Crew execution cancelled by plugin');
        }

        // Validate coordinator and QA agents are still active
        $coordinator = $crew->coordinator;
        if (! $coordinator || $coordinator->status !== AgentStatus::Active) {
            throw new InvalidArgumentException('Coordinator agent is not available.');
        }

        $qa = $crew->qaAgent;
        if (! $qa || $qa->status !== AgentStatus::Active) {
            throw new InvalidArgumentException('QA agent is not available.');
        }

        // Snapshot current crew configuration
        $configSnapshot = [
            'crew_id' => $crew->id,
            'process_type' => $crew->process_type->value,
            'max_task_iterations' => $crew->max_task_iterations,
            'quality_threshold' => $crew->quality_threshold,
            'coordinator' => [
                'id' => $coordinator->id,
                'name' => $coordinator->name,
                'role' => $coordinator->role,
                'goal' => $coordinator->goal,
                'provider' => $coordinator->provider,
                'model' => $coordinator->model,
            ],
            'qa_agent' => [
                'id' => $qa->id,
                'name' => $qa->name,
                'role' => $qa->role,
                'goal' => $qa->goal,
                'provider' => $qa->provider,
                'model' => $qa->model,
            ],
            'workers' => $crew->workerMembers()->with('agent')->get()->map(fn ($m) => [
                'id' => $m->agent->id,
                'name' => $m->agent->name,
                'slug' => $m->agent->slug,
                'role' => $m->agent->role,
                'goal' => $m->agent->goal,
                'provider' => $m->agent->provider,
                'model' => $m->agent->model,
                'skills' => $m->agent->skills->pluck('name')->toArray(),
            ])->toArray(),
            'settings' => $crew->settings ?? [],
        ];

        $execution = CrewExecution::create([
            'team_id' => $teamId,
            'crew_id' => $crew->id,
            'experiment_id' => $experimentId,
            'goal' => $goal,
            'status' => CrewExecutionStatus::Planning,
            'task_plan' => [],
            'config_snapshot' => $configSnapshot,
            'coordinator_iterations' => 0,
            'total_cost_credits' => 0,
            'started_at' => now(),
        ]);

        activity()
            ->performedOn($execution)
            ->withProperties([
                'crew_id' => $crew->id,
                'crew_name' => $crew->name,
                'goal' => $goal,
                'process_type' => $crew->process_type->value,
            ])
            ->log('crew.execution_started');

        ExecuteCrewJob::dispatch($execution->id, $teamId);

        return $execution;
    }
}
