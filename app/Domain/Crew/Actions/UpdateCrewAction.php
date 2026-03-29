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
    /**
     * @param  array<string, array{tool_allowlist?: string[]|string, max_steps?: int|string, max_credits?: int|string}>|null  $workerConstraints
     *                                                                                                                                            Per-worker policy overrides keyed by agent_id. Stored in CrewMember.config JSONB.
     *                                                                                                                                            Only applied when $workerAgentIds is also provided.
     */
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
        ?array $workerConstraints = null,
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

        if ($settings !== null) {
            $this->validateTaskRubrics($settings);
        }

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

        return DB::transaction(function () use ($crew, $name, $description, $coordinatorAgentId, $qaAgentId, $processType, $maxTaskIterations, $qualityThreshold, $workerAgentIds, $status, $settings, $effectiveCoordinator, $effectiveQa, $workerConstraints) {
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

                // Create new workers with optional per-member permission policy
                foreach ($workerAgentIds as $index => $agentId) {
                    $config = $this->buildMemberConfig($workerConstraints[$agentId] ?? []);
                    CrewMember::create([
                        'crew_id' => $crew->id,
                        'agent_id' => $agentId,
                        'role' => CrewMemberRole::Worker,
                        'sort_order' => $index,
                        'config' => $config,
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

    /**
     * Update the permission policy for a single crew member (tool_allowlist, max_steps, max_credits).
     * Merges into the existing config so other config keys are preserved.
     *
     * @param  array{tool_allowlist?: string[]|string, max_steps?: int|string, max_credits?: int|string}  $policy
     */
    public function updateMemberPolicy(CrewMember $member, array $policy): CrewMember
    {
        $config = (array) ($member->config ?? []);
        $built = $this->buildMemberConfig($policy);

        // Merge — allow clearing a constraint by passing an explicit null/empty value
        if (array_key_exists('tool_allowlist', $policy) && empty($policy['tool_allowlist'])) {
            unset($config['tool_allowlist']);
        } elseif (isset($built['tool_allowlist'])) {
            $config['tool_allowlist'] = $built['tool_allowlist'];
        }

        if (array_key_exists('max_steps', $policy) && ($policy['max_steps'] === '' || $policy['max_steps'] === null)) {
            unset($config['max_steps']);
        } elseif (isset($built['max_steps'])) {
            $config['max_steps'] = $built['max_steps'];
        }

        if (array_key_exists('max_credits', $policy) && ($policy['max_credits'] === '' || $policy['max_credits'] === null)) {
            unset($config['max_credits']);
        } elseif (isset($built['max_credits'])) {
            $config['max_credits'] = $built['max_credits'];
        }

        $member->update(['config' => $config]);

        return $member->fresh();
    }

    /**
     * Build a sanitised config array from raw constraint input.
     * Only sets keys that have non-empty values; empty constraints are omitted.
     *
     * @param  array{tool_allowlist?: string[]|string, max_steps?: int|string, max_credits?: int|string}  $raw
     * @return array<string, mixed>
     */
    private function buildMemberConfig(array $raw): array
    {
        $config = [];

        $toolAllowlist = $raw['tool_allowlist'] ?? null;
        if (! empty($toolAllowlist)) {
            if (is_string($toolAllowlist)) {
                $toolAllowlist = array_values(array_filter(array_map('trim', explode(',', $toolAllowlist))));
            }
            if (! empty($toolAllowlist)) {
                $config['tool_allowlist'] = $toolAllowlist;
            }
        }

        if (isset($raw['max_steps']) && $raw['max_steps'] !== '' && $raw['max_steps'] !== null) {
            $config['max_steps'] = max(1, (int) $raw['max_steps']);
        }

        if (isset($raw['max_credits']) && $raw['max_credits'] !== '' && $raw['max_credits'] !== null) {
            $config['max_credits'] = max(1, (int) $raw['max_credits']);
        }

        return $config;
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
