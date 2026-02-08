<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Budget\Actions\ReserveBudgetAction;
use App\Domain\Budget\Actions\SettleBudgetAction;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use App\Domain\Skill\Services\SchemaValidator;
use App\Domain\Skill\Services\SkillCostCalculator;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use InvalidArgumentException;

class ExecuteSkillAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly SchemaValidator $schemaValidator,
        private readonly SkillCostCalculator $costCalculator,
        private readonly ReserveBudgetAction $reserveBudget,
        private readonly SettleBudgetAction $settleBudget,
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
        // 1. Validate input against schema
        if (! empty($skill->input_schema)) {
            $validation = $this->schemaValidator->validate($input, $skill->input_schema);

            if (! $validation['valid']) {
                return $this->failExecution($skill, $teamId, $agentId, $experimentId, $input,
                    'Input validation failed: ' . implode('; ', $validation['errors'])
                );
            }
        }

        // 2. Resolve provider and model
        $resolvedProvider = $provider ?? $skill->configuration['provider'] ?? config('llm_pricing.default_provider', 'anthropic');
        $resolvedModel = $model ?? $skill->configuration['model'] ?? config('llm_pricing.default_model', 'claude-sonnet-4-5');

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
            $execution = SkillExecution::create([
                'skill_id' => $skill->id,
                'agent_id' => $agentId,
                'experiment_id' => $experimentId,
                'team_id' => $teamId,
                'status' => 'completed',
                'input' => $input,
                'output' => $output,
                'duration_ms' => $durationMs,
                'cost_credits' => $response->usage->costCredits,
            ]);

            // 7. Update skill stats
            $skill->recordExecution(true, $durationMs);

            // 8. Settle budget
            $this->settleBudget->execute($reservation, $response->usage->costCredits);

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

            return $this->failExecution($skill, $teamId, $agentId, $experimentId, $input, $e->getMessage(), $durationMs);
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
        $userPrompt = "Connector output:\n{$connectorResult}\n\nTask: " . ($skill->configuration['task'] ?? 'Summarize the data.');

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
        $userPrompt = "Rules:\n{$rules}\n\nInput:\n" . json_encode($input) . "\n\nEvaluate each rule and return the results.";

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
