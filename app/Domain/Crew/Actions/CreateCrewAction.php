<?php

namespace App\Domain\Crew\Actions;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewMemberRole;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateCrewAction
{
    public function execute(
        string $userId,
        string $name,
        string $coordinatorAgentId,
        string $qaAgentId,
        ?string $description = null,
        CrewProcessType $processType = CrewProcessType::Hierarchical,
        int $maxTaskIterations = 3,
        float $qualityThreshold = 0.70,
        array $workerAgentIds = [],
        array $settings = [],
        ?string $teamId = null,
    ): Crew {
        if ($coordinatorAgentId === $qaAgentId) {
            throw new InvalidArgumentException('Coordinator and QA agents must be different.');
        }

        // Validate all agents exist and are active
        $allAgentIds = array_unique(array_merge([$coordinatorAgentId, $qaAgentId], $workerAgentIds));
        $agents = Agent::withoutGlobalScopes()->whereIn('id', $allAgentIds)->get();

        foreach ($allAgentIds as $agentId) {
            $agent = $agents->firstWhere('id', $agentId);
            if (! $agent) {
                throw new InvalidArgumentException("Agent {$agentId} not found.");
            }
            if ($agent->status !== AgentStatus::Active) {
                throw new InvalidArgumentException("Agent '{$agent->name}' is not active.");
            }
        }

        // Workers must not include coordinator or QA
        $workerAgentIds = array_values(array_diff($workerAgentIds, [$coordinatorAgentId, $qaAgentId]));

        return DB::transaction(function () use ($userId, $name, $description, $coordinatorAgentId, $qaAgentId, $processType, $maxTaskIterations, $qualityThreshold, $workerAgentIds, $settings, $teamId) {
            $crew = Crew::create([
                'team_id' => $teamId,
                'user_id' => $userId,
                'coordinator_agent_id' => $coordinatorAgentId,
                'qa_agent_id' => $qaAgentId,
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::random(6),
                'description' => $description,
                'process_type' => $processType,
                'max_task_iterations' => $maxTaskIterations,
                'quality_threshold' => $qualityThreshold,
                'status' => CrewStatus::Draft,
                'settings' => $settings,
            ]);

            // Add worker members
            foreach ($workerAgentIds as $index => $agentId) {
                CrewMember::create([
                    'crew_id' => $crew->id,
                    'agent_id' => $agentId,
                    'role' => CrewMemberRole::Worker,
                    'sort_order' => $index,
                    'config' => [],
                ]);
            }

            activity()
                ->performedOn($crew)
                ->withProperties([
                    'coordinator_agent_id' => $coordinatorAgentId,
                    'qa_agent_id' => $qaAgentId,
                    'worker_count' => count($workerAgentIds),
                    'process_type' => $processType->value,
                ])
                ->log('crew.created');

            return $crew->load('members');
        });
    }
}
