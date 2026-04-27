<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Enums\AgentHookPosition;
use App\Domain\Agent\Enums\AgentReasoningStrategy;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Enums\FeedbackRating;
use App\Domain\Agent\Events\AgentExecuted;
use App\Domain\Agent\Events\AgentExecuting;
use App\Domain\Agent\Exceptions\ToolLoopCriticalException;
use App\Domain\Agent\Exceptions\ToolLoopSemanticException;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Agent\Models\AgentFeedback;
use App\Domain\Agent\Pipeline\AgentExecutionContext;
use App\Domain\Agent\Pipeline\Middleware\DetectClarificationNeeded;
use App\Domain\Agent\Pipeline\Middleware\InjectKnowledgeGraphContext;
use App\Domain\Agent\Pipeline\Middleware\InjectMemoryContext;
use App\Domain\Agent\Pipeline\Middleware\InjectWorldModel;
use App\Domain\Agent\Pipeline\Middleware\InjectRepoMapContext;
use App\Domain\Agent\Pipeline\Middleware\InjectTeamContext;
use App\Domain\Agent\Pipeline\Middleware\PreExecutionScout;
use App\Domain\Agent\Pipeline\Middleware\SummarizeContext;
use App\Domain\Agent\Services\AgentHookExecutor;
use App\Domain\Agent\Services\AgentPromptCompiler;
use App\Domain\Agent\Services\AgentRuntimeStateService;
use App\Domain\Agent\Services\SandboxedWorkspace;
use App\Domain\Agent\Services\ToolRecoveryOrchestrator;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Credential\Actions\ResolveProjectCredentialsAction;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Services\StepOutputBroadcaster;
use App\Domain\Memory\Jobs\ExtractMemoryJob;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\ExecuteSkillAction;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Services\SchemaValidator;
use App\Domain\Tool\Actions\ResolveAgentToolsAction;
use App\Domain\Tool\Exceptions\ResultAsAnswerException;
use App\Domain\Tool\Services\BashSidecarClient;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Enums\ReasoningEffort;
use App\Infrastructure\AI\Models\LlmRequestLog;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Pipeline;
use Illuminate\Support\Str;
use Prism\Prism\Tool;

class ExecuteAgentAction
{
    public function __construct(
        private readonly ExecuteSkillAction $executeSkill,
        private readonly AiGatewayInterface $gateway,
        private readonly ResolveAgentToolsAction $resolveTools,
        private readonly ResolveProjectCredentialsAction $resolveCredentials,
        private readonly ProviderResolver $providerResolver,
        private readonly PreExecutionScout $preExecutionScout,
        private readonly InjectMemoryContext $injectMemoryContext,
        private readonly InjectTeamContext $injectTeamContext,
        private readonly InjectKnowledgeGraphContext $injectKgContext,
        private readonly InjectWorldModel $injectWorldModel,
        private readonly InjectRepoMapContext $injectRepoMapContext,
        private readonly SummarizeContext $summarizeContext,
        private readonly DetectClarificationNeeded $detectClarification,
        private readonly ResolveTierConfigAction $resolveTierConfig,
        private readonly AgentRuntimeStateService $runtimeStateService,
        private readonly AgentHookExecutor $hookExecutor,
        private readonly AgentPromptCompiler $promptCompiler,
        private readonly ToolRecoveryOrchestrator $toolRecovery,
        private readonly SchemaValidator $schemaValidator,
    ) {}

    /**
     * Validate an agent's final output against its `output_schema` JSONB, if
     * one is defined. Returns null when no schema is present so the caller can
     * cheaply skip persistence.
     *
     * Best-effort: tries JSON-decode of the content first, falls back to
     * validating a `{ "result": string }` shape so free-form replies still
     * get a deterministic pass/fail.
     *
     * @return array{valid: bool, errors: list<string>}|null
     */
    private function validateAgentOutput(\App\Domain\Agent\Models\Agent $agent, ?string $content): ?array
    {
        $schema = $agent->output_schema ?? null;
        if (! is_array($schema) || $schema === []) {
            return null;
        }

        $decoded = is_string($content) ? json_decode($content, true) : null;
        $payload = is_array($decoded) ? $decoded : ['result' => (string) $content];

        return $this->schemaValidator->validate($payload, $schema);
    }

    /**
     * Build the retry user prompt that teaches the model what was wrong and
     * how to fix it. Kept deliberately terse — one paragraph of feedback,
     * followed by the JSON Schema the model must conform to.
     *
     * @param  array{valid: bool, errors: list<string>}  $eval
     */
    private function buildSchemaRetryPrompt(string $previousOutput, array $eval, array $schema): string
    {
        $errors = implode("\n- ", $eval['errors']);
        $schemaJson = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
        Your previous response failed output-schema validation.

        Previous response (first 500 chars):
        {$previousOutput}

        Schema errors:
        - {$errors}

        Return a JSON object that matches this schema exactly. No prose, no
        code fences — just the JSON:

        ```json
        {$schemaJson}
        ```
        PROMPT;
    }

    /**
     * Resolve the max-retries count for this agent. Nullable column → fall
     * back to `config('agent.output_schema.max_retries_default', 2)`. 0 is a
     * legitimate opt-out that preserves Sprint 8's validate-but-don't-retry
     * behavior.
     */
    private function resolveMaxRetries(\App\Domain\Agent\Models\Agent $agent): int
    {
        $value = $agent->output_schema_max_retries;
        if ($value === null) {
            $value = (int) config('agent.output_schema.max_retries_default', 2);
        }

        return max(0, min(5, (int) $value));
    }

    /**
     * Execute an agent by running its assigned tools (agentic loop)
     * or skills (sequential chain) depending on configuration.
     *
     * @param  string|null  $stepId  Playbook step ID — when provided, enables LLM output streaming
     * @return array{execution: AgentExecution, output: array|null}
     */
    /**
     * @param  string[]|null  $allowedToolIds  Crew-member-level tool allowlist; passed from ExecuteCrewTaskJob.
     * @param  int|null  $maxStepsOverride  Per-member max tool-call steps override from CrewMember config.
     */
    public function execute(
        Agent $agent,
        array $input,
        string $teamId,
        string $userId,
        ?string $experimentId = null,
        ?Project $project = null,
        ?string $stepId = null,
        ?array $allowedToolIds = null,
        ?int $maxStepsOverride = null,
    ): array {
        // Strip internal underscore-prefixed keys from external input (defense-in-depth).
        // Only trust these keys when injected by buildAgentAsTools (nested calls).
        $isNested = ! empty($input['_is_nested_call']);
        if (! $isNested) {
            $input = array_filter($input, fn ($key) => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
        }

        // Agent-as-tool depth guard: prevent infinite recursion
        $currentDepth = (int) ($input['_agent_tool_depth'] ?? 0);
        $maxDepth = config('agent.max_agent_tool_depth', 3);
        if ($currentDepth > $maxDepth) {
            return $this->failExecution(
                $agent, $teamId, $experimentId, $input,
                "Agent-as-tool max nesting depth ({$maxDepth}) exceeded",
            );
        }

        if (! $agent->hasBudgetRemaining()) {
            return $this->failExecution($agent, $teamId, $experimentId, $input, 'Agent budget cap reached');
        }

        // Plugin hook: allow plugins to inspect/mutate context or cancel execution
        $executing = new AgentExecuting($agent, $input);
        event($executing);
        if ($executing->cancel) {
            return $this->failExecution($agent, $teamId, $experimentId, $input, $executing->cancelReason ?? 'Cancelled by plugin');
        }
        $input = $executing->context;

        // Run user-configured pre-execution hooks (AOP pattern)
        $hookContext = $this->hookExecutor->run(AgentHookPosition::PreExecute, $agent, [
            'input' => $input,
            'system_prompt' => '',
        ]);
        if (! empty($hookContext['cancel'])) {
            return $this->failExecution($agent, $teamId, $experimentId, $input, $hookContext['cancel_reason'] ?? 'Blocked by agent hook');
        }
        $input = $hookContext['input'] ?? $input;

        // Run the semantic middleware pipeline to enrich / gate the execution context.
        // Each middleware may add system prompt parts, summarize input, or request clarification.
        $ctx = new AgentExecutionContext(
            agent: $agent,
            teamId: $teamId,
            userId: $userId,
            experimentId: $experimentId,
            project: $project,
            input: $input,
        );

        /** @var AgentExecutionContext $ctx */
        $ctx = Pipeline::send($ctx)
            ->through([
                $this->preExecutionScout,
                $this->injectMemoryContext,
                $this->injectTeamContext,
                $this->injectKgContext,
                $this->injectWorldModel,
                $this->injectRepoMapContext,
                $this->summarizeContext,
                $this->detectClarification,
            ])
            ->thenReturn();

        if ($ctx->requiresClarification) {
            return $this->requestClarification($ctx, $stepId);
        }

        // Use the (potentially summarized) input going forward
        $input = $ctx->input;

        // Workflow-driven execution: if input has a 'task' key (from workflow node prompt),
        // execute directly with LLM instead of skill chain
        if (! empty($input['task'])) {
            $result = $this->executeDirectPrompt($agent, $input, $teamId, $userId, $experimentId, $stepId, $ctx->systemPromptParts);
        } else {
            // Resolve tools for this agent (filtered by project restrictions).
            // Generate a sandbox ID so each execution gets an isolated filesystem workspace.
            $sandboxId = (string) Str::uuid();

            // Create a just-bash sidecar session before resolving tools so the workspace
            // can carry the session ID into ToolTranslator closures.
            $sidecarSessionId = null;
            if (config('agent.bash_sandbox_mode') === 'just_bash' && $agent->team_id) {
                $sidecarSessionId = "team:{$agent->team_id}:exec:{$sandboxId}";
                app(BashSidecarClient::class)->createSession($sidecarSessionId);
            }

            // Build a semantic query from the user input for tool pre-filtering.
            // This allows ResolveAgentToolsAction to filter tools by relevance when the
            // agent has many tools attached (above the semantic filter threshold).
            $semanticQuery = is_string($input) ? $input : json_encode(
                array_filter($input, fn ($k) => ! str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY),
            );

            $tools = $this->resolveTools->execute($agent, $project, $sandboxId, $sidecarSessionId, $currentDepth, $userId, $semanticQuery, $allowedToolIds);

            if (! empty($tools)) {
                try {
                    $result = $this->executeWithTools($agent, $input, $tools, $teamId, $userId, $experimentId, $project, $ctx->systemPromptParts, $maxStepsOverride);
                } finally {
                    // Destroy sidecar session and teardown sandbox regardless of success or failure
                    if ($sidecarSessionId !== null) {
                        app(BashSidecarClient::class)->destroySession($sidecarSessionId);
                    }
                    if ($agent->team_id) {
                        (new SandboxedWorkspace($sandboxId, $agent->id, $agent->team_id))->teardown();
                    }
                }
            } else {
                // Fallback: existing skill-chain execution
                $result = $this->executeSkillChain($agent, $input, $teamId, $userId, $experimentId);
            }
        }

        // Run user-configured post-execution hooks (AOP pattern)
        $postHookContext = $this->hookExecutor->run(AgentHookPosition::PostExecute, $agent, [
            'output' => is_array($result['output']) ? ($result['output']['response'] ?? json_encode($result['output'])) : '',
            'execution' => $result['execution'],
        ]);
        // Apply output transform if hook modified the output
        if (isset($postHookContext['output']) && $postHookContext['output'] !== '' && is_array($result['output'])) {
            $result['output']['response'] = $postHookContext['output'];
        }

        // Plugin hook: notify plugins of execution result
        event(new AgentExecuted($agent, $result['execution'], $result['output'] !== null));

        return $result;
    }

    /**
     * Agentic execution: LLM decides what to do using tools.
     *
     * @param  array<Tool>  $tools
     * @param  array<string>  $systemPromptParts  Extra sections injected by pipeline middleware
     * @return array{execution: AgentExecution, output: array|null}
     */
    /**
     * @param  int|null  $maxStepsOverride  Per-member cap from CrewMember config; overrides tier default when set.
     */
    private function executeWithTools(
        Agent $agent,
        array $input,
        array $tools,
        string $teamId,
        string $userId,
        ?string $experimentId,
        ?Project $project = null,
        array $systemPromptParts = [],
        ?int $maxStepsOverride = null,
    ): array {
        $startTime = hrtime(true);

        try {
            $team = Team::find($teamId);
            $resolved = $this->providerResolver->resolve(agent: $agent, team: $team);
            $tierConfig = $this->resolveTierConfig->execute($agent);

            // Per-member max_steps takes precedence over tier default when provided.
            $effectiveMaxSteps = $maxStepsOverride ?? $tierConfig['max_steps'];

            $systemPrompt = $this->buildAgentSystemPrompt($agent, $project, $input, $systemPromptParts, array_merge($tierConfig, ['max_steps' => $effectiveMaxSteps]));

            // Tier preference applies only when agent model is set to 'auto' or not set
            $model = ($agent->model !== null && $agent->model !== 'auto')
                ? $agent->model
                : $tierConfig['model_preference'];

            $request = new AiRequestDTO(
                provider: $resolved['provider'],
                model: $model,
                systemPrompt: $systemPrompt,
                userPrompt: json_encode($input),
                maxTokens: $tierConfig['max_tokens'],
                userId: $userId,
                teamId: $teamId,
                agentId: $agent->id,
                experimentId: $experimentId,
                purpose: 'agent.execute_with_tools',
                temperature: $tierConfig['temperature'],
                tools: $tools,
                maxSteps: $effectiveMaxSteps,
                thinkingBudget: $tierConfig['thinking_budget'] ?? null,
                effort: ReasoningEffort::tryFrom($tierConfig['reasoning_effort'] ?? ''),
                workingDirectory: $agent->config['working_directory'] ?? null,
                enablePromptCaching: true,
            );

            [$response, $recoveryTier, $isPartial] = $this->toolRecovery->attempt(
                request: $request,
                agent: $agent,
                team: $team,
                experimentId: $experimentId,
            );

            if ($isPartial) {
                Log::warning('Agent execution degraded — tool recovery reached tier 6', [
                    'agent_id' => $agent->id,
                    'experiment_id' => $experimentId,
                ]);
            }

            // Tool loop circuit breakers (BroodMind-inspired).
            // Warning threshold: log but continue. Critical threshold: fail fast.
            $warningThreshold = config('agent.tool_loop.warning_threshold', 8);
            $criticalThreshold = config('agent.tool_loop.critical_threshold', 12);

            if ($response->stepsCount >= $criticalThreshold) {
                throw new ToolLoopCriticalException($response->stepsCount, $criticalThreshold, $agent->id);
            }

            if ($response->stepsCount >= $warningThreshold) {
                Log::warning('Agent tool loop approaching critical threshold', [
                    'agent_id' => $agent->id,
                    'steps_count' => $response->stepsCount,
                    'tool_calls_count' => $response->toolCallsCount,
                    'warning_threshold' => $warningThreshold,
                    'critical_threshold' => $criticalThreshold,
                    'experiment_id' => $experimentId,
                ]);
            }

            // Semantic loop detection (DeerFlow-inspired): catches agents that call the
            // same tools with the same arguments repeatedly without making progress.
            // Complements the step-count check above — a 4-step run can still be a
            // semantic loop if all 4 steps are identical.
            $semanticMaxRepeat = $response->loopAnalysis['max_repeat'] ?? 0;
            $semanticWarn = config('agent.tool_loop.semantic_warn_threshold', 3);
            $semanticCritical = config('agent.tool_loop.semantic_critical_threshold', 5);

            if ($semanticMaxRepeat >= $semanticCritical) {
                throw new ToolLoopSemanticException($semanticMaxRepeat, $semanticCritical, $agent->id);
            }

            if ($semanticMaxRepeat >= $semanticWarn) {
                Log::warning('Agent semantic tool loop detected', [
                    'agent_id' => $agent->id,
                    'max_repeat' => $semanticMaxRepeat,
                    'experiment_id' => $experimentId,
                ]);
            }

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $costCredits = $response->usage->costCredits;

            // Serialise concurrent budget increments for the same agent with a row-level lock.
            // Must use the freshly-locked instance returned by first() — not the outer $agent.
            DB::transaction(function () use ($agent, $costCredits) {
                $locked = Agent::withoutGlobalScopes()->lockForUpdate()->where('id', $agent->id)->first();
                $locked->increment('budget_spent_credits', $costCredits);
            });

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
                'quality_details' => array_filter([
                    'tier' => $tierConfig['tier']->value,
                    'semantic_loop_max_repeat' => $semanticMaxRepeat >= $semanticWarn ? $semanticMaxRepeat : null,
                ]),
            ]);

            $this->runtimeStateService->recordExecution($agent, $response->usage);

            ExtractMemoryJob::dispatch($agent->id, $teamId, $execution->id)
                ->delay(now()->addSeconds(30));

            return [
                'execution' => $execution,
                'output' => ['result' => $response->content],
            ];
        } catch (ResultAsAnswerException $e) {
            // Tool flagged as result_as_answer — use its output directly, skip LLM summarization.
            // LLM tokens were already consumed before the tool threw, so we estimate cost
            // from the request log. The gateway logs each call to llm_request_logs.
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            // Retrieve the cost from the most recent LLM request log for this agent
            $costCredits = LlmRequestLog::where('agent_id', $agent->id)
                ->where('created_at', '>=', now()->subMinutes(2))
                ->orderByDesc('created_at')
                ->value('cost_credits') ?? 0;

            // Attribute budget spend to the agent
            if ($costCredits > 0) {
                DB::transaction(function () use ($agent, $costCredits) {
                    $locked = Agent::withoutGlobalScopes()->lockForUpdate()->where('id', $agent->id)->first();
                    $locked->increment('budget_spent_credits', $costCredits);
                });
            }

            $execution = AgentExecution::create([
                'agent_id' => $agent->id,
                'team_id' => $teamId,
                'experiment_id' => $experimentId,
                'status' => 'completed',
                'input' => $input,
                'output' => ['result' => $e->toolResult],
                'tools_used' => [['tool' => $e->toolName, 'result_as_answer' => true]],
                'tool_calls_count' => 1,
                'llm_steps_count' => 1,
                'duration_ms' => $durationMs,
                'cost_credits' => $costCredits,
                'quality_details' => ['result_as_answer' => true],
            ]);

            return [
                'execution' => $execution,
                'output' => ['result' => $e->toolResult],
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
     * @param  array<string>  $systemPromptParts  Extra sections injected by pipeline middleware
     * @return array{execution: AgentExecution, output: array|null}
     */
    private function executeDirectPrompt(
        Agent $agent,
        array $input,
        string $teamId,
        string $userId,
        ?string $experimentId,
        ?string $stepId = null,
        array $systemPromptParts = [],
    ): array {
        $startTime = hrtime(true);

        try {
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
            $tierConfig = $this->resolveTierConfig->execute($agent);
            // Direct prompt has no tool loop — suppress the budget section (max_steps irrelevant)
            $systemPrompt = $this->buildAgentSystemPrompt($agent, null, $input, $systemPromptParts, array_merge($tierConfig, ['max_steps' => 0]));

            $model = ($agent->model !== null && $agent->model !== 'auto')
                ? $agent->model
                : $tierConfig['model_preference'];

            $request = new AiRequestDTO(
                provider: $resolved['provider'],
                model: $model,
                systemPrompt: $systemPrompt,
                userPrompt: implode("\n\n", $userPromptParts),
                maxTokens: $tierConfig['max_tokens'],
                userId: $userId,
                teamId: $teamId,
                agentId: $agent->id,
                experimentId: $experimentId,
                purpose: 'agent.workflow_step',
                temperature: $tierConfig['temperature'],
                thinkingBudget: $tierConfig['thinking_budget'] ?? null,
                effort: ReasoningEffort::tryFrom($tierConfig['reasoning_effort'] ?? ''),
                workingDirectory: $agent->config['working_directory'] ?? null,
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

            // Optional typed output — validate + auto-retry on failure.
            $schemaEval = $this->validateAgentOutput($agent, $response->content);
            $retryAttempts = 0;
            $retryTrail = [];

            if ($schemaEval !== null && ! $schemaEval['valid']) {
                $maxRetries = $this->resolveMaxRetries($agent);

                while (! $schemaEval['valid'] && $retryAttempts < $maxRetries) {
                    $retryAttempts++;
                    $retryTrail[] = [
                        'attempt' => $retryAttempts,
                        'errors' => array_slice($schemaEval['errors'], 0, 5),
                    ];

                    $retryPrompt = $this->buildSchemaRetryPrompt(
                        previousOutput: mb_strimwidth((string) $response->content, 0, 500, '…'),
                        eval: $schemaEval,
                        schema: $agent->output_schema ?? [],
                    );

                    $retryRequest = new AiRequestDTO(
                        provider: $resolved['provider'],
                        model: $model,
                        systemPrompt: $systemPrompt,
                        userPrompt: implode("\n\n", $userPromptParts)."\n\n".$retryPrompt,
                        maxTokens: $tierConfig['max_tokens'],
                        userId: $userId,
                        teamId: $teamId,
                        agentId: $agent->id,
                        experimentId: $experimentId,
                        purpose: 'agent.workflow_step.schema_retry',
                        temperature: 0.1, // lower temp on retry for determinism
                        thinkingBudget: $tierConfig['thinking_budget'] ?? null,
                        workingDirectory: $agent->config['working_directory'] ?? null,
                    );

                    try {
                        $retryResponse = $this->gateway->complete($retryRequest);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('ExecuteAgentAction: schema retry failed', [
                            'agent_id' => $agent->id,
                            'attempt' => $retryAttempts,
                            'error' => $e->getMessage(),
                        ]);
                        break;
                    }

                    $response = $retryResponse;
                    $costCredits += $retryResponse->usage->costCredits;
                    $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
                    $schemaEval = $this->validateAgentOutput($agent, $response->content);
                }
            }

            DB::transaction(function () use ($agent, $costCredits) {
                $locked = Agent::withoutGlobalScopes()->lockForUpdate()->where('id', $agent->id)->first();
                $locked->increment('budget_spent_credits', $costCredits);
            });

            $qualityDetails = ['tier' => $tierConfig['tier']->value];
            if ($schemaEval !== null) {
                $qualityDetails['output_schema_valid'] = $schemaEval['valid'];
                $qualityDetails['output_schema_errors'] = array_slice($schemaEval['errors'], 0, 10);
                if ($retryAttempts > 0) {
                    $qualityDetails['output_schema_retries'] = $retryAttempts;
                    $qualityDetails['output_schema_retry_trail'] = $retryTrail;
                }
            }

            $execution = AgentExecution::create([
                'agent_id' => $agent->id,
                'team_id' => $teamId,
                'experiment_id' => $experimentId,
                'status' => 'completed',
                'input' => $input,
                'output' => ['result' => $response->content],
                'duration_ms' => $durationMs,
                'cost_credits' => $costCredits,
                'quality_details' => $qualityDetails,
            ]);

            $this->runtimeStateService->recordExecution($agent, $response->usage);

            ExtractMemoryJob::dispatch($agent->id, $teamId, $execution->id)
                ->delay(now()->addSeconds(30));

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
     *
     * @param  array<string>  $extraParts  Additional sections injected by the pipeline middleware
     */
    /**
     * @param  array<string, mixed>  $tierConfig  Resolved execution tier config (max_steps, etc.)
     */
    private function buildAgentSystemPrompt(Agent $agent, ?Project $project = null, array $input = [], array $extraParts = [], array $tierConfig = []): string
    {
        $parts = [];

        $parts[] = "You are an AI agent named \"{$agent->name}\".";

        if ($agent->role) {
            $parts[] = "Your role: {$agent->role}";
        }

        if ($agent->goal) {
            $parts[] = "Your goal: {$agent->goal}";
        }

        $compiledIdentity = $this->promptCompiler->compile($agent);
        if ($compiledIdentity !== '' && ! empty($agent->system_prompt_template)) {
            $parts[] = $compiledIdentity;
        } elseif ($agent->backstory) {
            $parts[] = "Background: {$agent->backstory}";
        }

        // Inject personality traits (SOUL.md)
        if (! empty($agent->personality)) {
            /** @var array<string, mixed> $personality */
            $personality = $agent->personality;
            $personalityParts = [];
            if ($personality['tone'] ?? null) {
                $personalityParts[] = "Tone: {$personality['tone']}";
            }
            if ($personality['communication_style'] ?? null) {
                $personalityParts[] = "Style: {$personality['communication_style']}";
            }
            if (! empty($personality['traits'])) {
                $personalityParts[] = 'Traits: '.implode(', ', $personality['traits']);
            }
            if (! empty($personality['behavioral_rules'])) {
                $personalityParts[] = "Rules:\n".implode("\n", array_map(fn ($r) => "- {$r}", $personality['behavioral_rules']));
            }
            if ($personality['response_format_preference'] ?? null) {
                $personalityParts[] = "Response format: {$personality['response_format_preference']}";
            }
            if (! empty($personalityParts)) {
                $parts[] = "## Personality & Communication Style\n".implode("\n", $personalityParts);
            }
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

        // Execution budget awareness — only meaningful for agentic (multi-step) calls
        $maxSteps = $tierConfig['max_steps'] ?? 0;
        if ($maxSteps > 1) {
            $targetStep = max(1, (int) floor($maxSteps * 0.80));
            $reservedSteps = $maxSteps - $targetStep;
            $parts[] = implode("\n", [
                '## Execution Budget',
                "You have a maximum of {$maxSteps} tool calls for this task.",
                "Plan to complete your core work by tool call {$targetStep}.",
                "Reserve the final {$reservedSteps} call(s) for summarising and delivering your result.",
                'If you approach the limit with work still remaining: prioritise the most important items,',
                'then deliver a partial result with clear notes on what was not completed.',
            ]);
        }

        // Reasoning strategy — shapes how the agent thinks and plans before acting
        $strategySection = ($agent->reasoning_strategy ?? AgentReasoningStrategy::FunctionCalling)->systemPromptSection();
        if ($strategySection !== '') {
            $parts[] = $strategySection;
        }

        // Explicit tool-selection chain-of-thought — improves traceability and reduces incorrect selections
        $parts[] = implode("\n", [
            '## Tool Selection',
            'Before each tool call, output one line:',
            '"I need [outcome] — calling [tool_name] because [reason]."',
        ]);

        // Browser tool usage policy — inject only if agent has a browser tool
        $hasBrowserTool = $agent->tools()
            ->where('type', 'built_in')
            ->get()
            ->contains(fn ($t) => ($t->transport_config['kind'] ?? null) === 'browser');
        if ($hasBrowserTool) {
            $parts[] = implode("\n", [
                '## Browser Tool Usage Policy',
                'The `browser_task` tool supports two modes via the `headless` parameter:',
                '',
                '- **headless="true"** (DEFAULT, use for most tasks):',
                '  - Fast (~3x faster than headful)',
                '  - Lower memory footprint',
                '  - Use for: scraping public pages, extracting data, navigating simple sites, fetching content.',
                '',
                '- **headless="false"** (use ONLY when needed):',
                '  - Runs real Chrome in a virtual display (Xvfb) — bypasses anti-bot detection.',
                '  - Slower and uses more memory.',
                '  - Use for: Reddit, Twitter/X, Cloudflare-protected sites, any site showing a JS challenge or "checking your browser" page.',
                '  - Also use when a previous headless attempt was blocked with a 403/captcha/security page.',
                '',
                'Rule of thumb: start with headless="true". If the page returns a bot-detection challenge, retry with headless="false". Do NOT use headless="false" preemptively for sites that work fine headless — it wastes time and memory.',
            ]);
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

        // Append sections injected by the semantic pipeline middleware (e.g. memory context)
        foreach ($extraParts as $part) {
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        // Few-shot examples from human feedback (opt-in via config)
        if (! empty($agent->config['use_few_shot_feedback'])) {
            $fewShotSection = $this->buildFewShotSection($agent);
            if ($fewShotSection !== '') {
                $parts[] = $fewShotSection;
            }
        }

        // Handoff capability — allow agent to transfer control to other agents
        if (! empty($agent->config['allow_handoff']) && ! empty($agent->config['handoff_agents'])) {
            $handoffAgents = Agent::whereIn('id', $agent->config['handoff_agents'])
                ->where('status', AgentStatus::Active)
                ->get(['id', 'name', 'role', 'goal']);

            if ($handoffAgents->isNotEmpty()) {
                $agentList = $handoffAgents->map(fn ($ha) => "- {$ha->name} (ID: {$ha->id}): {$ha->role} — {$ha->goal}")->implode("\n");
                $parts[] = "## Handoff Capability\nIf this task is better suited for another specialist, you may hand off by including a `_handoff` key in your JSON output:\n```json\n{\"_handoff\": {\"target_agent_id\": \"<agent-id>\", \"reason\": \"why\", \"context\": {\"key\": \"value\"}}}\n```\nAvailable agents for handoff:\n{$agentList}";
            }
        }

        $parts[] = 'Use the available tools to accomplish the task. Be thorough but efficient.';

        return implode("\n\n", $parts);
    }

    /**
     * Build a few-shot example section from recent positive feedback and corrections.
     * Returns empty string when no relevant examples are available.
     */
    private function buildFewShotSection(Agent $agent): string
    {
        $examples = AgentFeedback::where('agent_id', $agent->id)
            ->where('created_at', '>=', now()->subDays(90))
            ->where(function ($q) {
                $q->where('score', FeedbackRating::Positive->value)
                    ->orWhere(function ($q2) {
                        $q2->where('score', FeedbackRating::Negative->value)
                            ->whereNotNull('correction');
                    });
            })
            ->whereNotNull('input_snapshot')
            ->whereNotNull('output_snapshot')
            ->latest()
            ->limit(5)
            ->get();

        if ($examples->isEmpty()) {
            return '';
        }

        $parts = ['## Examples from Past Feedback'];

        foreach ($examples as $ex) {
            $inputPreview = mb_substr((string) $ex->input_snapshot, 0, 300);
            $outputPreview = $ex->correction
                ? mb_substr((string) $ex->correction, 0, 400)
                : mb_substr((string) $ex->output_snapshot, 0, 400);

            $label = $ex->correction ? 'Corrected output' : 'Approved output';

            $parts[] = "---\nInput: {$inputPreview}\n{$label}: {$outputPreview}";
        }

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

            // Track agent budget spend (locked to prevent concurrent over-spend)
            DB::transaction(function () use ($agent, $totalCost) {
                $locked = Agent::withoutGlobalScopes()->lockForUpdate()->where('id', $agent->id)->first();
                $locked->increment('budget_spent_credits', $totalCost);
            });

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

            ExtractMemoryJob::dispatch($agent->id, $teamId, $execution->id)
                ->delay(now()->addSeconds(30));

            return [
                'execution' => $execution,
                'output' => $currentInput,
            ];
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            DB::transaction(function () use ($agent, $totalCost) {
                $locked = Agent::withoutGlobalScopes()->lockForUpdate()->where('id', $agent->id)->first();
                $locked->increment('budget_spent_credits', $totalCost);
            });

            return $this->failExecution(
                $agent, $teamId, $experimentId, $input,
                $e->getMessage(), $durationMs, $totalCost, $skillResults,
            );
        }
    }

    /**
     * Create an ApprovalRequest of type 'clarification', transition the experiment to
     * AwaitingApproval, and return a synthetic AgentExecution with status 'awaiting_clarification'.
     *
     * The ApproveAction (or CompleteHumanTaskAction for clarification type) will re-dispatch
     * the playbook step job with the operator's answer injected into input['clarification_answer'].
     *
     * @return array{execution: AgentExecution, output: array}
     */
    private function requestClarification(AgentExecutionContext $ctx, ?string $stepId): array
    {
        $question = $ctx->clarificationQuestion ?? 'Please clarify your request.';

        try {
            $experiment = $ctx->experimentId
                ? Experiment::withoutGlobalScopes()->find($ctx->experimentId)
                : null;

            if ($experiment) {
                ApprovalRequest::withoutGlobalScopes()->create([
                    'experiment_id' => $experiment->id,
                    'team_id' => $experiment->team_id,
                    'status' => ApprovalStatus::Pending,
                    'context' => [
                        'type' => 'clarification',
                        'node_label' => 'Agent Clarification Required',
                        'instructions' => 'The agent needs clarification before proceeding. Please answer the question below.',
                        'question' => $question,
                        'agent_id' => $ctx->agent->id,
                        'step_id' => $stepId,
                        'original_input' => $ctx->input,
                        'experiment_title' => $experiment->title,
                    ],
                    'form_schema' => $ctx->clarificationFormSchema ?? [
                        'fields' => [
                            [
                                'name' => 'answer',
                                'label' => $question,
                                'type' => 'textarea',
                                'required' => true,
                                'rows' => 4,
                            ],
                        ],
                    ],
                    'expires_at' => now()->addHours(48),
                ]);

                app(TransitionExperimentAction::class)->execute(
                    experiment: $experiment,
                    toState: ExperimentStatus::AwaitingApproval,
                    reason: "Agent requires clarification: {$question}",
                );
            }
        } catch (\Throwable $e) {
            Log::warning('ExecuteAgentAction: failed to create clarification request', [
                'agent_id' => $ctx->agent->id,
                'experiment_id' => $ctx->experimentId,
                'error' => $e->getMessage(),
            ]);
        }

        $execution = AgentExecution::create([
            'agent_id' => $ctx->agent->id,
            'experiment_id' => $ctx->experimentId,
            'team_id' => $ctx->teamId,
            'status' => 'awaiting_clarification',
            'input' => $ctx->input,
            'output' => ['awaiting_clarification' => true, 'question' => $question],
            'duration_ms' => 0,
            'cost_credits' => 0,
        ]);

        return [
            'execution' => $execution,
            'output' => ['awaiting_clarification' => true, 'question' => $question],
        ];
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
