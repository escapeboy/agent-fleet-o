<?php

namespace App\Domain\Crew\Actions;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewMemberRole;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewMember;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateCrewAction
{
    public function execute(
        Crew $crew,
        ?string $name = null,
        ?string $description = null,
        ?string $coordinatorAgentId = null,
        ?string $qaAgentId = null,
        ?CrewProcessType $processType = null,
        ?int $maxTaskIterations = null,
        ?float $qualityThreshold = null,
        ?array $workerAgentIds = null,
        ?CrewStatus $status = null,
        ?array $settings = null,
    ): Crew {
        $effectiveCoordinator = $coordinatorAgentId ?? $crew->coordinator_agent_id;
        $effectiveQa = $qaAgentId ?? $crew->qa_agent_id;

        if ($effectiveCoordinator === $effectiveQa) {
            throw new InvalidArgumentException('Coordinator and QA agents must be different.');
        }

        // Block changes to coordinator/QA/workers if there are active executions
        $changingAgents = $coordinatorAgentId !== null || $qaAgentId !== null || $workerAgentIds !== null;
        if ($changingAgents) {
            $hasActive = $crew->executions()
                ->whereIn('status', [
                    CrewExecutionStatus::Planning->value,
                    CrewExecutionStatus::Executing->value,
                    CrewExecutionStatus::Paused->value,
                ])
                ->exists();

            if ($hasActive) {
                throw new InvalidArgumentException('Cannot change crew agents while executions are active.');
            }
        }

        // Validate new agents are active
        $agentsToValidate = array_filter([
            $coordinatorAgentId,
            $qaAgentId,
            ...($workerAgentIds ?? []),
        ]);

        if (! empty($agentsToValidate)) {
            $agents = Agent::withoutGlobalScopes()->whereIn('id', array_unique($agentsToValidate))->get();
            foreach ($agentsToValidate as $agentId) {
                $agent = $agents->firstWhere('id', $agentId);
                if (! $agent) {
                    throw new InvalidArgumentException("Agent {$agentId} not found.");
                }
                if ($agent->status !== AgentStatus::Active) {
                    throw new InvalidArgumentException("Agent '{$agent->name}' is not active.");
                }
            }
        }

        return DB::transaction(function () use ($crew, $name, $description, $coordinatorAgentId, $qaAgentId, $processType, $maxTaskIterations, $qualityThreshold, $workerAgentIds, $status, $settings, $effectiveCoordinator, $effectiveQa) {
            $changes = [];

            if ($name !== null && $name !== $crew->name) {
                $crew->name = $name;
                $changes['name'] = $name;
            }

            if ($description !== null) {
                $crew->description = $description;
                $changes['description'] = 'updated';
            }

            if ($coordinatorAgentId !== null && $coordinatorAgentId !== $crew->coordinator_agent_id) {
                $crew->coordinator_agent_id = $coordinatorAgentId;
                $changes['coordinator_agent_id'] = $coordinatorAgentId;
            }

            if ($qaAgentId !== null && $qaAgentId !== $crew->qa_agent_id) {
                $crew->qa_agent_id = $qaAgentId;
                $changes['qa_agent_id'] = $qaAgentId;
            }

            if ($processType !== null) {
                $crew->process_type = $processType;
                $changes['process_type'] = $processType->value;
            }

            if ($maxTaskIterations !== null) {
                $crew->max_task_iterations = $maxTaskIterations;
                $changes['max_task_iterations'] = $maxTaskIterations;
            }

            if ($qualityThreshold !== null) {
                $crew->quality_threshold = $qualityThreshold;
                $changes['quality_threshold'] = $qualityThreshold;
            }

            if ($status !== null) {
                $crew->status = $status;
                $changes['status'] = $status->value;
            }

            if ($settings !== null) {
                $crew->settings = $settings;
                $changes['settings'] = 'updated';
            }

            $crew->save();

            // Sync worker members if provided
            if ($workerAgentIds !== null) {
                // Remove workers that should not include coordinator or QA
                $workerAgentIds = array_values(array_diff($workerAgentIds, [$effectiveCoordinator, $effectiveQa]));

                // Delete existing workers
                $crew->members()->where('role', CrewMemberRole::Worker->value)->delete();

                // Create new workers
                foreach ($workerAgentIds as $index => $agentId) {
                    CrewMember::create([
                        'crew_id' => $crew->id,
                        'agent_id' => $agentId,
                        'role' => CrewMemberRole::Worker,
                        'sort_order' => $index,
                        'config' => [],
                    ]);
                }

                $changes['worker_count'] = count($workerAgentIds);
            }

            if (! empty($changes)) {
                activity()
                    ->performedOn($crew)
                    ->withProperties($changes)
                    ->log('crew.updated');
            }

            return $crew->fresh(['members']);
        });
    }
}
