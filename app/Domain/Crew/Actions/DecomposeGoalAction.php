<?php

namespace App\Domain\Crew\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Exceptions\MaxDelegationDepthExceededException;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewMember;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Experiment\Actions\PlanWithKnowledgeAction;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;

class DecomposeGoalAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
    ) {}

    /**
     * Have the coordinator agent decompose a goal into tasks.
     *
     * @return array<CrewTaskExecution>
     */
    public function execute(CrewExecution $execution): array
    {
        $maxDepth = config('app.max_delegation_depth', 5);
        if ($execution->delegation_depth >= $maxDepth) {
            throw new MaxDelegationDepthExceededException($execution->delegation_depth, $maxDepth);
        }

        $crew = $execution->crew;
        if ((bool) ($crew->settings['union_contributions'] ?? false)) {
            return $this->executeUnion($execution);
        }

        try {
            $enrichment = app(PlanWithKnowledgeAction::class)->execute($execution->goal, $execution->team_id);
            $knowledgeContext = "\n\nRelevant context from past experience and domain knowledge:\n".$enrichment['enriched_context'];
        } catch (\Throwable) {
            $knowledgeContext = '';
        }

        $config = $execution->config_snapshot;
        $coordinator = Agent::withoutGlobalScopes()->find($config['coordinator']['id']);

        if (! $coordinator || $coordinator->team_id !== $execution->team_id) {
            throw new \RuntimeException('Coordinator agent not found.');
        }

        $coordinatorMember = CrewMember::forAgentInCrew($coordinator->id, $execution->crew_id);
        $resolved = $coordinatorMember
            ? $this->providerResolver->forCrewRole($coordinatorMember)
            : $this->providerResolver->resolve(agent: $coordinator);

        $workerDescriptions = collect($config['workers'] ?? [])
            ->map(fn ($w) => "- {$w['name']} ({$w['role']}): {$w['goal']}. Skills: ".implode(', ', $w['skills'] ?? []))
            ->implode("\n");

        $isCoordinatorOnly = empty($config['workers']);

        $processType = $config['process_type'] ?? 'parallel';
        $isAdversarial = $processType === 'adversarial';

        if ($isAdversarial) {
            $systemPrompt = "You are {$coordinator->role}. {$coordinator->goal}\n\n"
                ."Your team will investigate this goal using an adversarial debate format.\n"
                ."Assign EXACTLY ONE hypothesis to each worker. Each worker will argue for their hypothesis and challenge others.\n"
                .(! $isCoordinatorOnly ? "Team members:\n{$workerDescriptions}\n\n" : '')
                ."Output valid JSON: an array of tasks with keys: title, description, assigned_to, expected_output.\n"
                ."Each task should define ONE hypothesis to investigate. No dependencies — all tasks run in parallel in Round 1.\n"
                ."Prefix each title with 'Round 1: Hypothesis — '.";

            $userPrompt = "Goal: {$execution->goal}\n\nCreate one hypothesis per team member as the starting positions for a structured debate.{$knowledgeContext}";
        } else {
            $systemPrompt = "You are {$coordinator->role}. {$coordinator->goal}\n\n"
                .($isCoordinatorOnly
                    ? "You are working alone. Decompose the goal into concrete tasks that you will execute yourself.\n"
                    : "Your team:\n{$workerDescriptions}\n\nDecompose the goal into tasks. Assign each to the most suitable team member by their name, or 'self' if you will do it.\n")
                ."\nOutput valid JSON: an array of task objects with keys: title, description, assigned_to, dependencies (array of 0-based indices), expected_output."
                ."\nOptionally include skip_condition to conditionally skip a task based on dependency outputs. Format: {\"field\": \"output.key\", \"operator\": \"==\", \"value\": \"...\"} or compound: {\"all\": [...]} / {\"any\": [...]}. Operators: ==, !=, >, <, >=, <=, contains, in, is_null, is_not_null.";

            $userPrompt = "Goal: {$execution->goal}\n\nProduce a task plan as a JSON array.{$knowledgeContext}";
        }

        $request = new AiRequestDTO(
            provider: $resolved['provider'],
            model: $resolved['model'],
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: 4096,
            userId: $execution->resolveUserId(),
            teamId: $execution->team_id,
            agentId: $coordinator->id,
            purpose: 'crew.decompose_goal',
            temperature: 0.3,
        );

        $response = $this->gateway->complete($request);

        $tasks = $this->parseTaskPlan($response->content, $config);

        // Update execution with the plan
        $execution->update([
            'task_plan' => $tasks,
            'total_cost_credits' => $execution->total_cost_credits + $response->usage->costCredits,
        ]);

        // Create CrewTaskExecution records
        $taskExecutions = [];
        $workerMap = collect($config['workers'] ?? [])->keyBy('name');

        // First pass: create all task records with sort_order-based depends_on temporarily,
        // so that the UUID remapping in the second pass can resolve them.
        foreach ($tasks as $index => $task) {
            $assignedAgent = null;
            $assignedTo = $task['assigned_to'] ?? 'self';

            if ($assignedTo === 'self' || $isCoordinatorOnly) {
                $assignedAgent = $coordinator;
            } else {
                // Match by name (case-insensitive)
                $worker = $workerMap->first(fn ($w) => strcasecmp($w['name'], $assignedTo) === 0);
                if ($worker) {
                    $found = Agent::withoutGlobalScopes()->find($worker['id']);
                    // Only use the agent if it belongs to the same team as the execution
                    if ($found && $found->team_id === $execution->team_id) {
                        $assignedAgent = $found;
                    }
                }
            }

            $dependencies = array_map('intval', $task['dependencies'] ?? []);

            $taskExecution = CrewTaskExecution::create([
                'crew_execution_id' => $execution->id,
                'agent_id' => $assignedAgent?->id,
                'title' => $task['title'] ?? 'Task '.($index + 1),
                'description' => $task['description'] ?? '',
                // Tasks with dependencies start as Blocked; they are unblocked by DependencyGraph::autoUnblock()
                // once all their dependencies reach a satisfied terminal state (Validated or Skipped).
                'status' => ! empty($dependencies) ? CrewTaskStatus::Blocked : CrewTaskStatus::Pending,
                'input_context' => array_filter([
                    'expected_output' => $task['expected_output'] ?? null,
                    'assigned_to' => $assignedTo,
                    // Tag adversarial round-1 tasks so the orchestrator can identify them by round
                    'debate_round' => $isAdversarial ? 1 : null,
                ]),
                'depends_on' => $dependencies, // Temporarily sort_order integers; remapped to UUIDs below
                'skip_condition' => $task['skip_condition'] ?? null,
                'attempt_number' => 1,
                'max_attempts' => $execution->config_snapshot['max_task_iterations'] ?? 3,
                'sort_order' => $index,
            ]);

            $taskExecutions[] = $taskExecution;
        }

        // Second pass: remap sort_order integer dependencies to UUID strings.
        // DependencyGraph::autoUnblock() uses whereJsonContains('depends_on', uuid), so
        // depends_on must store task UUIDs, not sort_order integers.
        foreach ($taskExecutions as $taskExecution) {
            $sortOrderDeps = $taskExecution->depends_on ?? [];
            if (! empty($sortOrderDeps)) {
                $uuidDeps = [];
                foreach ($sortOrderDeps as $depIndex) {
                    if (isset($taskExecutions[(int) $depIndex])) {
                        $uuidDeps[] = $taskExecutions[(int) $depIndex]->id;
                    }
                }
                $taskExecution->update(['depends_on' => $uuidDeps]);
            }
        }

        return $taskExecutions;
    }

    /**
     * Union contribution mode: each worker independently proposes tasks, then
     * the coordinator orders the deduplicated union. Prevents GroupThink by
     * ensuring no worker sees another's proposal during the contribution phase.
     *
     * @return array<CrewTaskExecution>
     */
    private function executeUnion(CrewExecution $execution): array
    {
        $config = $execution->config_snapshot;
        $workers = $config['workers'] ?? [];

        if (empty($workers)) {
            return $this->execute($execution);
        }

        $workerPrompt = "You are %s. %s\n\nIndependently propose tasks to accomplish this goal.\n"
            ."Goal: {$execution->goal}\n\n"
            .'Output a JSON array of tasks: [{"title": string, "description": string, "expected_output": string}]';

        $allProposals = [];

        foreach ($workers as $workerConfig) {
            $agent = Agent::withoutGlobalScopes()
                ->where('team_id', $execution->team_id)
                ->find($workerConfig['id'] ?? null);

            if (! $agent) {
                continue;
            }

            $member = CrewMember::forAgentInCrew($agent->id, $execution->crew_id);
            $resolved = $member
                ? $this->providerResolver->forCrewRole($member)
                : $this->providerResolver->resolve(agent: $agent);

            $request = new AiRequestDTO(
                provider: $resolved['provider'],
                model: $resolved['model'],
                systemPrompt: sprintf($workerPrompt, $workerConfig['name'], $workerConfig['goal'] ?? ''),
                userPrompt: "Propose tasks for: {$execution->goal}",
                maxTokens: 2048,
                userId: $execution->resolveUserId(),
                teamId: $execution->team_id,
                agentId: $agent->id,
                purpose: 'crew.union_contribute',
                temperature: 0.4,
            );

            try {
                $response = $this->gateway->complete($request);
                $proposed = $this->parseTaskPlan($response->content, $config);
                foreach ($proposed as $task) {
                    $task['_contributor'] = $workerConfig['name'];
                    $allProposals[] = $task;
                }
                $execution->increment('total_cost_credits', $response->usage->costCredits);
            } catch (\Throwable) {
                // Skip failed contributors — union of remaining workers still valid
            }
        }

        if (empty($allProposals)) {
            return $this->execute($execution);
        }

        // Deduplicate proposals by title similarity (case-insensitive normalized compare)
        $seen = [];
        $unique = [];
        foreach ($allProposals as $proposal) {
            $key = trim(strtolower(preg_replace('/\s+/', ' ', $proposal['title'] ?? '')));
            if ($key && ! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $proposal;
            }
        }

        // Coordinator orders the union (does NOT filter — only assigns sequence and dependencies)
        $coordinator = Agent::withoutGlobalScopes()->find($config['coordinator']['id']);
        if (! $coordinator || $coordinator->team_id !== $execution->team_id) {
            throw new \RuntimeException('Coordinator agent not found.');
        }

        $coordinatorMember = CrewMember::forAgentInCrew($coordinator->id, $execution->crew_id);
        $resolved = $coordinatorMember
            ? $this->providerResolver->forCrewRole($coordinatorMember)
            : $this->providerResolver->resolve(agent: $coordinator);

        $unionJson = json_encode($unique, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $orderRequest = new AiRequestDTO(
            provider: $resolved['provider'],
            model: $resolved['model'],
            systemPrompt: "You are {$coordinator->role}. {$coordinator->goal}\n\n"
                .'Order and prioritize the following tasks contributed by the team. '
                ."Do NOT remove tasks — only re-order and add dependencies where needed.\n"
                .'Output a JSON array with the same tasks in optimal execution order. '
                .'Each task: {"title": string, "description": string, "expected_output": string, "depends_on": [sort_order_int]}',
            userPrompt: "Order these tasks:\n{$unionJson}",
            maxTokens: 4096,
            userId: $execution->resolveUserId(),
            teamId: $execution->team_id,
            agentId: $coordinator->id,
            purpose: 'crew.union_order',
            temperature: 0.2,
        );

        $orderResponse = $this->gateway->complete($orderRequest);
        $orderedTasks = $this->parseTaskPlan($orderResponse->content, $config);
        $execution->increment('total_cost_credits', $orderResponse->usage->costCredits);

        $execution->update(['task_plan' => $orderedTasks]);

        return $this->createTaskExecutions($execution, $orderedTasks, $config);
    }

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     * @return array<CrewTaskExecution>
     */
    private function createTaskExecutions(CrewExecution $execution, array $tasks, array $config): array
    {
        $workerMap = collect($config['workers'] ?? [])->keyBy('name');
        $taskExecutions = [];

        foreach ($tasks as $index => $task) {
            $assignedTo = $task['assigned_to'] ?? null;
            $assignedAgent = $assignedTo && isset($workerMap[$assignedTo])
                ? Agent::withoutGlobalScopes()
                    ->where('team_id', $execution->team_id)
                    ->find($workerMap[$assignedTo]['id'] ?? null)
                : null;

            $dependencies = array_filter(
                is_array($task['depends_on'] ?? null) ? $task['depends_on'] : [],
                fn ($d) => is_int($d) && $d < $index,
            );

            $taskExecution = CrewTaskExecution::create([
                'crew_execution_id' => $execution->id,
                'team_id' => $execution->team_id,
                'agent_id' => $assignedAgent?->id,
                'title' => $task['title'] ?? 'Task '.($index + 1),
                'description' => $task['description'] ?? '',
                'status' => ! empty($dependencies) ? CrewTaskStatus::Blocked : CrewTaskStatus::Pending,
                'input_context' => array_filter([
                    'expected_output' => $task['expected_output'] ?? null,
                    'assigned_to' => $assignedTo,
                ]),
                'depends_on' => $dependencies,
                'attempt_number' => 1,
                'max_attempts' => $config['max_task_iterations'] ?? 3,
                'sort_order' => $index,
            ]);

            $taskExecutions[] = $taskExecution;
        }

        foreach ($taskExecutions as $taskExecution) {
            $sortOrderDeps = $taskExecution->depends_on ?? [];
            if (! empty($sortOrderDeps)) {
                $uuidDeps = [];
                foreach ($sortOrderDeps as $depIndex) {
                    if (isset($taskExecutions[(int) $depIndex])) {
                        $uuidDeps[] = $taskExecutions[(int) $depIndex]->id;
                    }
                }
                $taskExecution->update(['depends_on' => $uuidDeps]);
            }
        }

        return $taskExecutions;
    }

    private function parseTaskPlan(string $content, array $config): array
    {
        // Strip markdown code fences if present
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*\n?/', '', $content);
            $content = preg_replace('/\n?```\s*$/', '', $content);
        }

        $parsed = json_decode($content, true, 512, JSON_UNESCAPED_UNICODE);

        if (! is_array($parsed)) {
            throw new \RuntimeException('Coordinator did not produce a valid JSON task plan.');
        }

        // Normalize: if the response is an object with a "tasks" key, extract it
        if (isset($parsed['tasks']) && is_array($parsed['tasks'])) {
            $parsed = $parsed['tasks'];
        }

        // Validate and sanitize each task's skip_condition
        foreach ($parsed as &$task) {
            if (isset($task['skip_condition'])) {
                if (! $this->isValidSkipCondition($task['skip_condition'])) {
                    $task['skip_condition'] = null; // Strip invalid conditions
                }
            }
        }
        unset($task);

        // Ensure sequential array
        return array_values($parsed);
    }

    /**
     * Validate skip_condition structure to prevent malformed/malicious conditions.
     * Only allows known keys: field, operator, value, all, any.
     */
    private function isValidSkipCondition(mixed $condition, int $depth = 0): bool
    {
        if (! is_array($condition) || $depth > 10) {
            return false;
        }

        // Compound: all/any
        if (isset($condition['all'])) {
            if (! is_array($condition['all'])) {
                return false;
            }
            foreach ($condition['all'] as $sub) {
                if (! $this->isValidSkipCondition($sub, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        if (isset($condition['any'])) {
            if (! is_array($condition['any'])) {
                return false;
            }
            foreach ($condition['any'] as $sub) {
                if (! $this->isValidSkipCondition($sub, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        // Simple condition: must have 'field' and 'operator'
        if (! isset($condition['field']) || ! is_string($condition['field'])) {
            return false;
        }

        $allowedOperators = ['==', '!=', '>', '<', '>=', '<=', 'contains', 'not_contains', 'in', 'not_in', 'is_null', 'is_not_null'];
        $operator = $condition['operator'] ?? '==';

        return in_array($operator, $allowedOperators, true);
    }
}
