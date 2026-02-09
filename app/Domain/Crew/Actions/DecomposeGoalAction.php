<?php

namespace App\Domain\Crew\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
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
        $config = $execution->config_snapshot;
        $coordinator = Agent::withoutGlobalScopes()->find($config['coordinator']['id']);

        if (! $coordinator) {
            throw new \RuntimeException('Coordinator agent not found.');
        }

        $resolved = $this->providerResolver->resolve(agent: $coordinator);

        $workerDescriptions = collect($config['workers'] ?? [])
            ->map(fn ($w) => "- {$w['name']} ({$w['role']}): {$w['goal']}. Skills: ".implode(', ', $w['skills'] ?? []))
            ->implode("\n");

        $isCoordinatorOnly = empty($config['workers']);

        $systemPrompt = "You are {$coordinator->role}. {$coordinator->goal}\n\n"
            .($isCoordinatorOnly
                ? "You are working alone. Decompose the goal into concrete tasks that you will execute yourself.\n"
                : "Your team:\n{$workerDescriptions}\n\nDecompose the goal into tasks. Assign each to the most suitable team member by their name, or 'self' if you will do it.\n")
            ."\nOutput valid JSON: an array of task objects with keys: title, description, assigned_to, dependencies (array of 0-based indices), expected_output.";

        $userPrompt = "Goal: {$execution->goal}\n\nProduce a task plan as a JSON array.";

        $request = new AiRequestDTO(
            provider: $resolved['provider'],
            model: $resolved['model'],
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: 4096,
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

        foreach ($tasks as $index => $task) {
            $assignedAgent = null;
            $assignedTo = $task['assigned_to'] ?? 'self';

            if ($assignedTo === 'self' || $isCoordinatorOnly) {
                $assignedAgent = $coordinator;
            } else {
                // Match by name (case-insensitive)
                $worker = $workerMap->first(fn ($w) => strcasecmp($w['name'], $assignedTo) === 0);
                if ($worker) {
                    $assignedAgent = Agent::withoutGlobalScopes()->find($worker['id']);
                }
            }

            $dependencies = array_map('intval', $task['dependencies'] ?? []);

            $taskExecution = CrewTaskExecution::create([
                'crew_execution_id' => $execution->id,
                'agent_id' => $assignedAgent?->id,
                'title' => $task['title'] ?? "Task ".($index + 1),
                'description' => $task['description'] ?? '',
                'status' => CrewTaskStatus::Pending,
                'input_context' => [
                    'expected_output' => $task['expected_output'] ?? null,
                    'assigned_to' => $assignedTo,
                ],
                'depends_on' => $dependencies,
                'attempt_number' => 1,
                'max_attempts' => $execution->config_snapshot['max_task_iterations'] ?? 3,
                'sort_order' => $index,
            ]);

            $taskExecutions[] = $taskExecution;
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

        // Ensure sequential array
        return array_values($parsed);
    }
}
