<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Skill\Actions\ExecuteSkillAction;
use App\Domain\Skill\Models\Skill;

class ExecuteAgentAction
{
    public function __construct(
        private readonly ExecuteSkillAction $executeSkill,
    ) {}

    /**
     * Execute an agent by running its assigned skills in priority order.
     * Each skill's output is passed as context to the next skill.
     *
     * @return array{execution: AgentExecution, output: array|null}
     */
    public function execute(
        Agent $agent,
        array $input,
        string $teamId,
        string $userId,
        ?string $experimentId = null,
    ): array {
        if (! $agent->hasBudgetRemaining()) {
            return $this->failExecution($agent, $teamId, $experimentId, $input, 'Agent budget cap reached');
        }

        $skills = $agent->skills()->get();

        if ($skills->isEmpty()) {
            return $this->failExecution($agent, $teamId, $experimentId, $input, 'Agent has no skills assigned');
        }

        $startTime = hrtime(true);
        $skillResults = [];
        $totalCost = 0;
        $currentInput = $input;

        try {
            foreach ($skills as $skill) {
                /** @var Skill $skill */
                $overrides = $skill->pivot->overrides ?? [];
                $provider = $overrides['provider'] ?? $agent->provider;
                $model = $overrides['model'] ?? $agent->model;

                $result = $this->executeSkill->execute(
                    skill: $skill,
                    input: $currentInput,
                    teamId: $teamId,
                    userId: $userId,
                    agentId: $agent->id,
                    experimentId: $experimentId,
                    provider: $provider,
                    model: $model,
                );

                $skillResults[] = [
                    'skill_id' => $skill->id,
                    'skill_name' => $skill->name,
                    'status' => $result['execution']->status,
                    'cost_credits' => $result['execution']->cost_credits,
                ];

                $totalCost += $result['execution']->cost_credits;

                // If skill failed, stop the chain
                if ($result['output'] === null) {
                    $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

                    return $this->failExecution(
                        $agent, $teamId, $experimentId, $input,
                        "Skill '{$skill->name}' failed: {$result['execution']->error_message}",
                        $durationMs, $totalCost, $skillResults, $result['output'],
                    );
                }

                // Pass output as input to next skill
                $currentInput = is_array($result['output']) ? $result['output'] : ['result' => $result['output']];
            }

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            // Track agent budget spend
            $agent->increment('budget_spent_credits', $totalCost);

            $execution = AgentExecution::create([
                'agent_id' => $agent->id,
                'experiment_id' => $experimentId,
                'team_id' => $teamId,
                'status' => 'completed',
                'input' => $input,
                'output' => $currentInput,
                'skills_executed' => $skillResults,
                'duration_ms' => $durationMs,
                'cost_credits' => $totalCost,
            ]);

            return [
                'execution' => $execution,
                'output' => $currentInput,
            ];
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $agent->increment('budget_spent_credits', $totalCost);

            return $this->failExecution(
                $agent, $teamId, $experimentId, $input,
                $e->getMessage(), $durationMs, $totalCost, $skillResults,
            );
        }
    }

    /**
     * @return array{execution: AgentExecution, output: null}
     */
    private function failExecution(
        Agent $agent,
        string $teamId,
        ?string $experimentId,
        array $input,
        string $errorMessage,
        int $durationMs = 0,
        int $costCredits = 0,
        array $skillResults = [],
        mixed $lastOutput = null,
    ): array {
        $execution = AgentExecution::create([
            'agent_id' => $agent->id,
            'experiment_id' => $experimentId,
            'team_id' => $teamId,
            'status' => 'failed',
            'input' => $input,
            'output' => $lastOutput,
            'skills_executed' => $skillResults,
            'duration_ms' => $durationMs,
            'cost_credits' => $costCredits,
            'error_message' => $errorMessage,
        ]);

        return [
            'execution' => $execution,
            'output' => null,
        ];
    }
}
