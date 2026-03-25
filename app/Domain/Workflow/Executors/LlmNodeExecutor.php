<?php

namespace App\Domain\Workflow\Executors;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Contracts\NodeExecutorInterface;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;

/**
 * Executes an LLM node — a direct AI call with a prompt template.
 *
 * Config shape:
 * {
 *   "model": "anthropic/claude-haiku-4-5",   // provider/model
 *   "prompt_template": "Summarise:\n\n{{context}}",
 *   "system_prompt": "You are a concise summariser.",   // optional
 *   "max_tokens": 512,
 *   "temperature": 0.3
 * }
 */
class LlmNodeExecutor implements NodeExecutorInterface
{
    use InterpolatesTemplates;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    public function execute(WorkflowNode $node, PlaybookStep $step, Experiment $experiment): array
    {
        $config = $this->parseConfig($node->config);
        $context = $this->buildStepContext($step, $experiment);

        [$provider, $model] = $this->parseModel($config['model'] ?? 'anthropic/claude-haiku-4-5');

        $promptTemplate = $config['prompt_template'] ?? '';
        $prompt = $this->interpolate($promptTemplate, $context);

        $systemPrompt = $this->interpolate(
            $config['system_prompt'] ?? 'You are a helpful AI assistant.',
            $context,
        );

        $response = $this->gateway->complete(new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: $systemPrompt,
            userPrompt: $prompt,
            maxTokens: (int) ($config['max_tokens'] ?? 1024),
            temperature: (float) ($config['temperature'] ?? 0.7),
            teamId: $experiment->team_id,
            experimentId: $experiment->id,
            purpose: 'llm_node',
        ));

        return [
            'text' => $response->content,
            'tokens_used' => $response->usage?->totalTokens ?? 0,
        ];
    }

    /** @return array{string, string} */
    private function parseModel(string $modelString): array
    {
        if (str_contains($modelString, '/')) {
            [$provider, $model] = explode('/', $modelString, 2);

            return [$provider, $model];
        }

        return ['anthropic', $modelString];
    }
}
