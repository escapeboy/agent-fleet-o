<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Credential\Actions\ResolveProjectCredentialsAction;
use App\Domain\Experiment\Services\StepOutputBroadcaster;
use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\ExecuteSkillAction;
use App\Domain\Skill\Models\Skill;
use App\Domain\Tool\Actions\ResolveAgentToolsAction;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Prism\Prism\Tool;

class ExecuteAgentAction
{
    public function __construct(
        private readonly ExecuteSkillAction $executeSkill,
        private readonly AiGatewayInterface $gateway,
        private readonly ResolveAgentToolsAction $resolveTools,
        private readonly ResolveProjectCredentialsAction $resolveCredentials,
        private readonly ProviderResolver $providerResolver,
        private readonly RetrieveRelevantMemoriesAction $retrieveMemories,
    ) {}

    /**
     * Execute an agent by running its assigned tools (agentic loop)
     * or skills (sequential chain) depending on configuration.
     *
     * @param  string|null  $stepId  Playbook step ID — when provided, enables LLM output streaming
     * @return array{execution: AgentExecution, output: array|null}
     */
    public function execute(
        Agent $agent,
        array $input,
        string $teamId,
        string $userId,
        ?string $experimentId = null,
        ?Project $project = null,
        ?string $stepId = null,
    ): array {
        if (! $agent->hasBudgetRemaining()) {
            return $this->failExecution($agent, $teamId, $experimentId, $input, 'Agent budget cap reached');
        }

        // Workflow-driven execution: if input has a 'task' key (from workflow node prompt),
        // execute directly with LLM instead of skill chain
        if (! empty($input['task'])) {
            return $this->executeDirectPrompt($agent, $input, $teamId, $userId, $experimentId, $stepId);
        }

        // Resolve tools for this agent (filtered by project restrictions)
        $tools = $this->resolveTools->execute($agent, $project);

        if (! empty($tools)) {
            return $this->executeWithTools($agent, $input, $tools, $teamId, $userId, $experimentId, $project);
        }

        // Fallback: existing skill-chain execution
        return $this->executeSkillChain($agent, $input, $teamId, $userId, $experimentId);
    }

    /**
     * Agentic execution: LLM decides what to do using tools.
     *
     * @param  array<Tool>  $tools
     * @return array{execution: AgentExecution, output: array|null}
     */
    private function executeWithTools(
        Agent $agent,
        array $input,
        array $tools,
        string $teamId,
        string $userId,
        ?string $experimentId,
        ?Project $project = null,
    ): array {
        $startTime = hrtime(true);

        try {
            $systemPrompt = $this->buildAgentSystemPrompt($agent, $project, $input);
            $team = Team::find($teamId);
            $resolved = $this->providerResolver->resolve(agent: $agent, team: $team);

            $request = new AiRequestDTO(
                provider: $resolved['provider'],
                model: $resolved['model'],
                systemPrompt: $systemPrompt,
                userPrompt: json_encode($input),
                maxTokens: $agent->config['max_tokens'] ?? 4096,
                teamId: $teamId,
                agentId: $agent->id,
                experimentId: $experimentId,
                purpose: 'agent.execute_with_tools',
                temperature: $agent->config['temperature'] ?? 0.7,
                tools: $tools,
                maxSteps: $agent->config['max_steps'] ?? 10,
            );

            $response = $this->gateway->complete($request);

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $costCredits = $response->usage->costCredits;

            $agent->increment('budget_spent_credits', $costCredits);

            $execution = AgentExecution::create([
                'agent_id' => $agent->id,
                'team_id' => $teamId,
                'experiment_id' => $experimentId,
                'status' => 'completed',
                'input' => $input,
                'output' => ['result' => $response->content],
                'tools_used' => $response->toolResults ?? [],
                'tool_calls_count' => $response->toolCallsCount,
                'llm_steps_count' => $response->stepsCount,
                'duration_ms' => $durationMs,
                'cost_credits' => $costCredits,
            ]);

            return [
                'execution' => $execution,
                'output' => ['result' => $response->content],
            ];
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return $this->failExecution(
                $agent, $teamId, $experimentId, $input,
                $e->getMessage(), $durationMs,
            );
        }
    }

    /**
     * Execute an agent by sending the workflow task prompt directly to the LLM.
     * Used for workflow-driven steps where the node config provides the task.
     * When $stepId is provided, streams output to Redis for real-time UI updates.
     *
     * @return array{execution: AgentExecution, output: array|null}
     */
    private function executeDirectPrompt(
        Agent $agent,
        array $input,
        string $teamId,
        string $userId,
        ?string $experimentId,
        ?string $stepId = null,
    ): array {
        $startTime = hrtime(true);

        try {
            $systemPrompt = $this->buildAgentSystemPrompt($agent, null, $input);

            // Build user prompt from task + goal + context
            $userPromptParts = [];
            if (! empty($input['task'])) {
                $userPromptParts[] = "## Task\n".$input['task'];
            }
            if (! empty($input['goal'])) {
                $userPromptParts[] = "## Project Goal\n".$input['goal'];
            }
            if (! empty($input['context'])) {
                $userPromptParts[] = "## Context from Previous Steps\n".json_encode($input['context'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }

            $team = Team::find($teamId);
            $resolved = $this->providerResolver->resolve(agent: $agent, team: $team);

            $request = new AiRequestDTO(
                provider: $resolved['provider'],
                model: $resolved['model'],
                systemPrompt: $systemPrompt,
                userPrompt: implode("\n\n", $userPromptParts),
                maxTokens: $agent->config['max_tokens'] ?? 8192,
                teamId: $teamId,
                agentId: $agent->id,
                experimentId: $experimentId,
                purpose: 'agent.workflow_step',
                temperature: $agent->config['temperature'] ?? 0.7,
            );

            // Use streaming when we have a step ID (enables real-time output in UI)
            if ($stepId) {
                $broadcaster = app(StepOutputBroadcaster::class);
                $response = $this->gateway->stream($request, function (string $chunk) use ($broadcaster, $stepId) {
                    $broadcaster->broadcastChunk($stepId, $chunk);
                });
            } else {
                $response = $this->gateway->complete($request);
            }

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $costCredits = $response->usage->costCredits;

            $agent->increment('budget_spent_credits', $costCredits);

            $execution = AgentExecution::create([
                'agent_id' => $agent->id,
                'team_id' => $teamId,
                'experiment_id' => $experimentId,
                'status' => 'completed',
                'input' => $input,
                'output' => ['result' => $response->content],
                'duration_ms' => $durationMs,
                'cost_credits' => $costCredits,
            ]);

            return [
                'execution' => $execution,
                'output' => ['result' => $response->content],
            ];
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return $this->failExecution(
                $agent, $teamId, $experimentId, $input,
                $e->getMessage(), $durationMs,
            );
        }
    }

    /**
     * Build a rich system prompt that gives the agent its identity and context.
     */
    private function buildAgentSystemPrompt(Agent $agent, ?Project $project = null, array $input = []): string
    {
        $parts = [];

        $parts[] = "You are an AI agent named \"{$agent->name}\".";

        if ($agent->role) {
            $parts[] = "Your role: {$agent->role}";
        }

        if ($agent->goal) {
            $parts[] = "Your goal: {$agent->goal}";
        }

        if ($agent->backstory) {
            $parts[] = "Background: {$agent->backstory}";
        }

        // Include skill descriptions as context
        $skills = $agent->skills()->get();
        if ($skills->isNotEmpty()) {
            $skillList = $skills->map(fn (Skill $s) => "- {$s->name}: {$s->description}")->implode("\n");
            $parts[] = "You have domain knowledge in these areas:\n{$skillList}";
        }

        if (! empty($agent->constraints)) {
            $constraintList = collect($agent->constraints)->map(fn ($c) => "- {$c}")->implode("\n");
            $parts[] = "Constraints:\n{$constraintList}";
        }

        // Include available credentials from project scope
        $credentials = $this->resolveCredentials->execute($project);
        if (! empty($credentials)) {
            $credentialList = collect($credentials)->map(function ($c) {
                $desc = $c['description'] ? ": {$c['description']}" : '';

                return "- {$c['name']} ({$c['type']}, id: {$c['id']}){$desc}";
            })->implode("\n");
            $parts[] = "## Available Credentials\nYou have access to the following credentials for authenticating with external services. Request a credential by its ID when you need to authenticate.\n\n{$credentialList}";
        }

        // Inject relevant memories from past executions
        if (config('memory.enabled', true) && ! empty($input)) {
            $queryText = is_array($input) ? json_encode($input) : (string) $input;
            $memories = $this->retrieveMemories->execute(
                agentId: $agent->id,
                query: $queryText,
                projectId: $project?->id,
            );

            if ($memories->isNotEmpty()) {
                $memoryList = $memories->map(fn ($m) => "- {$m->content}")->implode("\n");
                $parts[] = "## Relevant Context from Past Executions\n{$memoryList}";
            }
        }

        $parts[] = 'Use the available tools to accomplish the task. Be thorough but efficient.';

        return implode("\n\n", $parts);
    }

    /**
     * Execute an agent by running its assigned skills in priority order.
     * Each skill's output is passed as context to the next skill.
     *
     * @return array{execution: AgentExecution, output: array|null}
     */
    private function executeSkillChain(
        Agent $agent,
        array $input,
        string $teamId,
        string $userId,
        ?string $experimentId,
    ): array {
        $skills = $agent->skills()->get();

        if ($skills->isEmpty()) {
            return $this->failExecution($agent, $teamId, $experimentId, $input, 'Agent has no skills or tools assigned');
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
