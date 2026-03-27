<?php

namespace App\Domain\Crew\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\CrewAgentMessage;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;

class BuildAdversarialRoundTasksAction
{
    /**
     * Create the next debate round's tasks.
     *
     * Each worker receives a task that includes all other workers' findings from the previous round,
     * prompting them to challenge competing hypotheses and defend their own.
     *
     * @param  array<CrewTaskExecution>  $previousRoundTasks
     * @return array<CrewTaskExecution>
     */
    public function execute(CrewExecution $execution, int $nextRound, array $previousRoundTasks): array
    {
        $config = $execution->config_snapshot;
        $workers = collect($config['workers'] ?? []);
        $previousRoundIds = array_map(fn ($t) => $t->id, $previousRoundTasks);

        // Collect findings from the previous round's messages
        $findings = CrewAgentMessage::where('crew_execution_id', $execution->id)
            ->where('round', $nextRound - 1)
            ->where('message_type', 'finding')
            ->get();

        $newTasks = [];
        $sortBase = count($previousRoundTasks) * ($nextRound - 1);

        foreach ($workers as $index => $workerConfig) {
            $agent = Agent::withoutGlobalScopes()->find($workerConfig['id']);
            if (! $agent) {
                continue;
            }

            // Build context: other agents' findings (exclude this agent's own findings)
            $otherFindings = $findings
                ->where('sender_agent_id', '!=', $agent->id)
                ->map(fn ($m) => "Agent findings:\n{$m->content}")
                ->implode("\n\n");

            $task = CrewTaskExecution::create([
                'crew_execution_id' => $execution->id,
                'agent_id' => $agent->id,
                'title' => "Round {$nextRound}: Challenge & defend — {$workerConfig['name']}",
                'description' => "You are in debate round {$nextRound}. Review the other agents' findings below and:\n"
                    ."1. Challenge any hypothesis that contradicts your own theory\n"
                    ."2. Defend your theory with additional evidence\n"
                    ."3. Update your conclusion if another agent's evidence is compelling\n\n"
                    ."Other agents' findings:\n{$otherFindings}",
                'status' => CrewTaskStatus::Pending,
                'input_context' => [
                    'debate_round' => $nextRound,
                    'worker_name' => $workerConfig['name'],
                ],
                'depends_on' => [],
                'attempt_number' => 1,
                'max_attempts' => $config['max_task_iterations'] ?? 3,
                'sort_order' => $sortBase + $index,
            ]);

            $newTasks[] = $task;
        }

        return $newTasks;
    }
}
