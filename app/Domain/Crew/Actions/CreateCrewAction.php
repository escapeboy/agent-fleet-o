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

        $this->validateTaskRubrics($settings);

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

        return DB::transaction(function () use (
            $userId, $name, $description, $coordinatorAgentId, $qaAgentId,
            $processType, $maxTaskIterations, $qualityThreshold, $workerAgentIds, $settings, $teamId
        ) {
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

    /**
     * Validate task_rubrics in crew settings to prevent prompt-injection payloads
     * from reaching QA agent system prompts via ValidateTaskOutputAction.
     *
     * @throws InvalidArgumentException
     */
    private function validateTaskRubrics(array $settings): void
    {
        $rubrics = $settings['task_rubrics'] ?? null;

        if ($rubrics === null) {
            return;
        }

        if (! is_array($rubrics)) {
            throw new InvalidArgumentException('settings.task_rubrics must be an array.');
        }

        if (count($rubrics) > 10) {
            throw new InvalidArgumentException('settings.task_rubrics may not contain more than 10 rubric types.');
        }

        foreach ($rubrics as $key => $rubric) {
            if (! is_string($key) || mb_strlen($key) > 50) {
                throw new InvalidArgumentException('Rubric key must be a string of at most 50 characters.');
            }

            if (! is_array($rubric)) {
                throw new InvalidArgumentException("Rubric '{$key}' must be an array.");
            }

            if (isset($rubric['min_score'])) {
                $minScore = $rubric['min_score'];
                if (! is_numeric($minScore) || $minScore < 0 || $minScore > 1) {
                    throw new InvalidArgumentException("Rubric '{$key}' min_score must be a number between 0 and 1.");
                }
            }

            $criteria = $rubric['criteria'] ?? null;
            if (! is_array($criteria) || empty($criteria)) {
                throw new InvalidArgumentException("Rubric '{$key}' must have a non-empty 'criteria' array.");
            }

            if (count($criteria) > 10) {
                throw new InvalidArgumentException("Rubric '{$key}' may not have more than 10 criteria.");
            }

            foreach ($criteria as $i => $criterion) {
                if (! is_array($criterion)) {
                    throw new InvalidArgumentException("Rubric '{$key}' criterion #{$i} must be an array.");
                }

                $name = $criterion['name'] ?? null;
                if (! is_string($name) || mb_strlen(trim($name)) === 0) {
                    throw new InvalidArgumentException("Rubric '{$key}' criterion #{$i} must have a non-empty 'name'.");
                }
                if (mb_strlen($name) > 100) {
                    throw new InvalidArgumentException("Rubric '{$key}' criterion name must not exceed 100 characters.");
                }
                if (! preg_match('/^[\w\s\-]+$/u', $name)) {
                    throw new InvalidArgumentException("Rubric '{$key}' criterion name '{$name}' may only contain letters, numbers, spaces, and hyphens.");
                }

                $description = $criterion['description'] ?? null;
                if (! is_string($description) || mb_strlen(trim($description)) === 0) {
                    throw new InvalidArgumentException("Rubric '{$key}' criterion '{$name}' must have a non-empty 'description'.");
                }
                if (mb_strlen($description) > 500) {
                    throw new InvalidArgumentException("Rubric '{$key}' criterion '{$name}' description must not exceed 500 characters.");
                }

                $weight = $criterion['weight'] ?? null;
                if (! is_numeric($weight) || $weight < 0 || $weight > 1) {
                    throw new InvalidArgumentException("Rubric '{$key}' criterion '{$name}' weight must be a number between 0 and 1.");
                }
            }
        }
    }
}
