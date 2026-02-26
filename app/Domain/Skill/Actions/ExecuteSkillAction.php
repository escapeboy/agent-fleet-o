<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Budget\Actions\ReserveBudgetAction;
use App\Domain\Budget\Actions\SettleBudgetAction;
use App\Domain\Marketplace\Actions\RecordMarketplaceUsageAction;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Exceptions\SkillProviderIncompatibleException;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use App\Domain\Skill\Services\MultiModelConsensusService;
use App\Domain\Skill\Services\SchemaValidator;
use App\Domain\Skill\Services\SkillCompatibilityChecker;
use App\Domain\Skill\Services\SkillCostCalculator;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Services\ProviderResolver;

class ExecuteSkillAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly SchemaValidator $schemaValidator,
        private readonly SkillCostCalculator $costCalculator,
        private readonly ReserveBudgetAction $reserveBudget,
        private readonly SettleBudgetAction $settleBudget,
        private readonly ProviderResolver $providerResolver,
        private readonly SkillCompatibilityChecker $compatibilityChecker,
        private readonly RecordMarketplaceUsageAction $recordMarketplaceUsage,
        private readonly MultiModelConsensusService $consensusService,
        private readonly ExecuteCodeExecutionSkillAction $executeCodeExecution,
        private readonly ExecuteBrowserSkillAction $executeBrowserSkill,
    ) {}

    /**
     * Execute a skill following the full pipeline:
     * validate → reserve budget → call AI → validate output → record → settle budget
     *
     * @param  array  $input  The input data matching skill.input_schema
     * @param  string|null  $provider  Override provider (agent → team default → platform)
     * @param  string|null  $model  Override model
     * @return array{execution: SkillExecution, output: array|string|null}
     */
    public function execute(
        Skill $skill,
        array $input,
        string $teamId,
        string $userId,
        ?string $agentId = null,
        ?string $experimentId = null,
        ?string $provider = null,
        ?string $model = null,
    ): array {
        // CodeExecution has its own full pipeline (worktree + Docker sandbox + approval)
        if ($skill->type === SkillType::CodeExecution->value) {
            return $this->executeCodeExecution->execute($skill, $input, $teamId, $userId, $agentId, $experimentId);
        }

        // Browser Automation calls Browserless REST API directly — no LLM, no budget reservation
        if ($skill->type === SkillType::Browser->value) {
            return $this->executeBrowserSkill->execute($skill, $input, $teamId, $userId, $agentId, $experimentId);
        }

        // 0. Check provider compatibility (if requirements declared)
        if (! empty($skill->provider_requirements)) {
            $team = Team::withoutGlobalScopes()->find($teamId);
            if ($team) {
                try {
                    $this->compatibilityChecker->assertCompatible($skill, $team);
                } catch (SkillProviderIncompatibleException $e) {
                    return $this->failExecution($skill, $teamId, $agentId, $experimentId, $input, $e->getMessage());
                }
            }
        }

        // 1. Validate input against schema
        if (! empty($skill->input_schema)) {
            $validation = $this->schemaValidator->validate($input, $skill->input_schema);

            if (! $validation['valid']) {
                return $this->failExecution($skill, $teamId, $agentId, $experimentId, $input,
                    'Input validation failed: '.implode('; ', $validation['errors']),
                );
            }
        }

        // 2. Resolve provider and model via ProviderResolver hierarchy
        if ($provider && $model) {
            $resolvedProvider = $provider;
            $resolvedModel = $model;
        } else {
            $agent = $agentId ? Agent::find($agentId) : null;
            $team = $teamId ? Team::find($teamId) : null;
            $resolved = $this->providerResolver->resolve($skill, $agent, $team);
            $resolvedProvider = $provider ?? $resolved['provider'];
            $resolvedModel = $model ?? $resolved['model'];
        }

        // 3. Estimate and reserve budget
        $estimatedCost = $this->costCalculator->estimate($skill, $resolvedProvider, $resolvedModel);
        $reservation = $this->reserveBudget->execute(
            userId: $userId,
            teamId: $teamId,
            amount: $estimatedCost,
            experimentId: $experimentId,
            description: "Skill execution: {$skill->name}",
        );

        $startTime = hrtime(true);

        try {
            // 4. Execute based on skill type
            $response = $this->executeByType($skill, $input, $resolvedProvider, $resolvedModel, $teamId, $userId, $agentId, $experimentId);

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            // 5. Validate output if schema defined
            $output = $response->parsedOutput ?? json_decode($response->content, true);
            $schemaValid = true;

            if (! empty($skill->output_schema) && is_array($output)) {
                $outputValidation = $this->schemaValidator->validate($output, $skill->output_schema);
                $schemaValid = $outputValidation['valid'];
            }

            // 6. Create execution record
            $executionData = [
                'skill_id' => $skill->id,
                'agent_id' => $agentId,
                'experiment_id' => $experimentId,
                'team_id' => $teamId,
                'status' => 'completed',
                'input' => $input,
                'output' => $output,
                'duration_ms' => $durationMs,
                'cost_credits' => $response->usage->costCredits,
            ];

            if ($skill->type === SkillType::MultiModelConsensus->value && is_array($output)) {
                $executionData['confidence_score'] = $output['confidence_score'] ?? null;
                $executionData['consensus_level'] = $output['consensus_level'] ?? null;
                $executionData['peer_reviews'] = $output['peer_reviews'] ?? null;
                $executionData['evaluation_method'] = 'multi_model_consensus';
                $config = is_array($skill->configuration) ? $skill->configuration : [];
                $executionData['judge_model'] = ($config['judge_model']['provider'] ?? 'anthropic')
                    .'/'
                    .($config['judge_model']['model'] ?? 'claude-sonnet-4-5');
                // Store only the synthesized answer in output, not the metadata columns
                $executionData['output'] = [
                    'answer' => $output['answer'] ?? $response->content,
                    'dissenting_view' => $output['dissenting_view'] ?? null,
                ];
            }

            $execution = SkillExecution::create($executionData);

            // 7. Update skill stats
            $skill->recordExecution(true, $durationMs);

            // 8. Settle budget
            $this->settleBudget->execute($reservation, $response->usage->costCredits);

            // 9. Record marketplace usage (no-op if not from marketplace)
            if ($skill->source_listing_id) {
                $this->recordMarketplaceUsage->execute($execution);
            }

            return [
                'execution' => $execution,
                'output' => $output,
            ];
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            // Settle budget with zero actual cost on failure
            $this->settleBudget->execute($reservation, 0);

            // Record failed execution
            $skill->recordExecution(false, $durationMs);

            $failResult = $this->failExecution($skill, $teamId, $agentId, $experimentId, $input, $e->getMessage(), $durationMs);

            // Record failed marketplace usage
            if ($skill->source_listing_id) {
                $this->recordMarketplaceUsage->execute($failResult['execution']);
            }

            return $failResult;
        }
    }

    private function executeByType(
        Skill $skill,
        array $input,
        string $provider,
        string $model,
        string $teamId,
        string $userId,
        ?string $agentId,
        ?string $experimentId,
    ): AiResponseDTO {
        return match ($skill->type) {
            SkillType::Llm, SkillType::Hybrid => $this->executeLlmSkill($skill, $input, $provider, $model, $teamId, $userId, $agentId, $experimentId),
            SkillType::Connector => $this->executeConnectorSkill($skill, $input, $provider, $model, $teamId, $userId, $agentId, $experimentId),
            SkillType::Rule => $this->executeRuleSkill($skill, $input, $provider, $model, $teamId, $userId, $agentId, $experimentId),
            SkillType::Guardrail => $this->executeLlmSkill($skill, $input, $provider, $model, $teamId, $userId, $agentId, $experimentId),
            SkillType::MultiModelConsensus => $this->executeMultiModelConsensusSkill($skill, $input, $teamId, $userId, $agentId, $experimentId),
            SkillType::CodeExecution, SkillType::Browser => throw new \LogicException('CodeExecution and Browser skill types must be short-circuited before reaching executeByType.'),
        };
    }

    private function executeLlmSkill(
        Skill $skill,
        array $input,
        string $provider,
        string $model,
        string $teamId,
        string $userId,
        ?string $agentId,
        ?string $experimentId,
    ): AiResponseDTO {
        $systemPrompt = $skill->system_prompt ?? 'You are a helpful assistant.';
        $userPrompt = $this->buildUserPrompt($skill, $input);

        $request = new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: $skill->configuration['max_tokens'] ?? 4096,
            outputSchema: null, // Structured output via PrismPHP schemas handled at gateway level
            userId: $userId,
            teamId: $teamId,
            experimentId: $experimentId,
            agentId: $agentId,
            purpose: "skill:{$skill->slug}",
            temperature: $skill->configuration['temperature'] ?? 0.7,
        );

        return $this->gateway->complete($request);
    }

    private function executeConnectorSkill(
        Skill $skill,
        array $input,
        string $provider,
        string $model,
        string $teamId,
        string $userId,
        ?string $agentId,
        ?string $experimentId,
    ): AiResponseDTO {
        // Connector skills wrap an LLM call that interprets the connector output
        // The connector config specifies which connector to invoke
        $connectorResult = json_encode($input);

        $systemPrompt = $skill->system_prompt ?? 'Process the following connector output and produce a structured result.';
        $userPrompt = "Connector output:\n{$connectorResult}\n\nTask: ".($skill->configuration['task'] ?? 'Summarize the data.');

        $request = new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: $skill->configuration['max_tokens'] ?? 2048,
            userId: $userId,
            teamId: $teamId,
            experimentId: $experimentId,
            agentId: $agentId,
            purpose: "skill:{$skill->slug}:connector",
            temperature: $skill->configuration['temperature'] ?? 0.3,
        );

        return $this->gateway->complete($request);
    }

    private function executeRuleSkill(
        Skill $skill,
        array $input,
        string $provider,
        string $model,
        string $teamId,
        string $userId,
        ?string $agentId,
        ?string $experimentId,
    ): AiResponseDTO {
        // Rule skills use LLM to evaluate rules against input
        $rules = json_encode($skill->configuration['rules'] ?? []);

        $systemPrompt = $skill->system_prompt ?? 'Evaluate the following rules against the input and return a JSON result.';
        $userPrompt = "Rules:\n{$rules}\n\nInput:\n".json_encode($input)."\n\nEvaluate each rule and return the results.";

        $request = new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: $skill->configuration['max_tokens'] ?? 2048,
            userId: $userId,
            teamId: $teamId,
            experimentId: $experimentId,
            agentId: $agentId,
            purpose: "skill:{$skill->slug}:rule",
            temperature: 0.1,
        );

        return $this->gateway->complete($request);
    }

    private function executeMultiModelConsensusSkill(
        Skill $skill,
        array $input,
        string $teamId,
        string $userId,
        ?string $agentId,
        ?string $experimentId,
    ): AiResponseDTO {
        $config = is_array($skill->configuration) ? $skill->configuration : [];
        $models = $config['models'] ?? [
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
            ['provider' => 'openai', 'model' => 'gpt-4o'],
            ['provider' => 'google', 'model' => 'gemini-2.5-flash'],
        ];
        $judgeModel = $config['judge_model'] ?? ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'];

        $result = $this->consensusService->run(
            prompt: $this->buildUserPrompt($skill, $input),
            systemPrompt: $skill->system_prompt ?? '',
            models: $models,
            judgeModel: $judgeModel,
            teamId: $teamId,
            userId: $userId,
            experimentId: $experimentId,
            agentId: $agentId,
        );

        /** @var AiResponseDTO $response */
        $response = $result['response'];

        // Enrich parsedOutput with peer_reviews so the execution record can persist it
        return new AiResponseDTO(
            content: $response->content,
            parsedOutput: array_merge($response->parsedOutput ?? [], [
                'peer_reviews' => $result['peer_reviews'],
            ]),
            usage: $response->usage,
            provider: $response->provider,
            model: $response->model,
            latencyMs: $response->latencyMs,
        );
    }

    private function buildUserPrompt(Skill $skill, array $input): string
    {
        $template = $skill->configuration['prompt_template'] ?? null;

        if ($template) {
            // Simple variable substitution: {{variable_name}}
            return preg_replace_callback('/\{\{(\w+)\}\}/', function ($matches) use ($input) {
                return $input[$matches[1]] ?? $matches[0];
            }, $template);
        }

        return json_encode($input);
    }

    /**
     * @return array{execution: SkillExecution, output: null}
     */
    private function failExecution(
        Skill $skill,
        string $teamId,
        ?string $agentId,
        ?string $experimentId,
        array $input,
        string $errorMessage,
        int $durationMs = 0,
    ): array {
        $execution = SkillExecution::create([
            'skill_id' => $skill->id,
            'agent_id' => $agentId,
            'experiment_id' => $experimentId,
            'team_id' => $teamId,
            'status' => 'failed',
            'input' => $input,
            'output' => null,
            'duration_ms' => $durationMs,
            'cost_credits' => 0,
            'error_message' => $errorMessage,
        ]);

        return [
            'execution' => $execution,
            'output' => null,
        ];
    }
}
