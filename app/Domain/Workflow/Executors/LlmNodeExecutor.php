<?php

namespace App\Domain\Workflow\Executors;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Memory\Actions\UnifiedMemorySearchAction;
use App\Domain\Workflow\Contracts\NodeExecutorInterface;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * Executes an LLM node with one of four operations:
 *
 * text_complete (default): prompt → text response
 * {
 *   "operation": "text_complete",
 *   "model": "anthropic/claude-haiku-4-5",
 *   "prompt_template": "Summarise:\n\n{{context}}",
 *   "system_prompt": "You are a concise summariser.",
 *   "max_tokens": 512,
 *   "temperature": 0.3
 * }
 *
 * extract: prompt → validated JSON matching output_schema
 * {
 *   "operation": "extract",
 *   "model": "anthropic/claude-haiku-4-5",
 *   "prompt_template": "Extract from:\n\n{{context.result}}",
 *   "output_schema": {"sentiment": {"type": "string"}, "score": {"type": "number"}}
 * }
 *
 * embed: text → float[] vector embedding
 * {
 *   "operation": "embed",
 *   "text_template": "{{context.result}}",
 *   "embed_provider": "openai",
 *   "embed_model": "text-embedding-3-small"
 * }
 *
 * search: query → top-K memory results
 * {
 *   "operation": "search",
 *   "query_template": "{{context.result}}",
 *   "search_k": 5
 * }
 */
class LlmNodeExecutor implements NodeExecutorInterface
{
    use InterpolatesTemplates;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly UnifiedMemorySearchAction $memorySearch,
    ) {}

    public function execute(WorkflowNode $node, PlaybookStep $step, Experiment $experiment): array
    {
        $config = $this->parseConfig($node->config);
        $operation = $config['operation'] ?? 'text_complete';

        return match ($operation) {
            'embed' => $this->executeEmbed($config, $step, $experiment),
            'extract' => $this->executeExtract($config, $step, $experiment),
            'search' => $this->executeSearch($config, $step, $experiment),
            default => $this->executeTextComplete($config, $step, $experiment),
        };
    }

    // ─── Operations ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function executeTextComplete(array $config, PlaybookStep $step, Experiment $experiment): array
    {
        $context = $this->buildStepContext($step, $experiment);

        [$provider, $model] = $this->parseModel($config['model'] ?? 'anthropic/claude-haiku-4-5');

        $prompt = $this->interpolate($config['prompt_template'] ?? '', $context);
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
            'tokens_used' => $response->usage->totalTokens(),
        ];
    }

    /** @return array<string, mixed> */
    private function executeExtract(array $config, PlaybookStep $step, Experiment $experiment): array
    {
        $context = $this->buildStepContext($step, $experiment);

        [$provider, $model] = $this->parseModel($config['model'] ?? 'anthropic/claude-haiku-4-5');

        $prompt = $this->interpolate($config['prompt_template'] ?? 'Extract structured data from:\n\n{{context}}', $context);
        $schemaFields = $config['output_schema'] ?? [];
        $outputSchema = $this->buildObjectSchema($schemaFields);

        $response = $this->gateway->complete(new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: 'You are a precise data extraction assistant. Extract only the requested fields. Return null for fields you cannot find.',
            userPrompt: $prompt,
            maxTokens: (int) ($config['max_tokens'] ?? 512),
            outputSchema: $outputSchema,
            temperature: 0.0,
            teamId: $experiment->team_id,
            experimentId: $experiment->id,
            purpose: 'llm_node_extract',
        ));

        $extracted = is_array($response->parsedOutput) ? $response->parsedOutput : [];

        return [
            'extracted' => $extracted,
            ...$extracted,
        ];
    }

    /** @return array<string, mixed> */
    private function executeEmbed(array $config, PlaybookStep $step, Experiment $experiment): array
    {
        $context = $this->buildStepContext($step, $experiment);

        $textTemplate = $config['text_template'] ?? '{{context.result}}';
        $text = $this->interpolate($textTemplate, $context);

        $provider = $config['embed_provider'] ?? config('memory.embedding_provider', 'openai');
        $model = $config['embed_model'] ?? config('memory.embedding_model', 'text-embedding-3-small');

        $response = Prism::embeddings()
            ->using($provider, $model)
            ->fromInput($text)
            ->generate();

        $vector = $response->embeddings[0]->embedding;

        return [
            'vector' => $vector,
            'dimensions' => count($vector),
            'model' => "{$provider}/{$model}",
            'input_text' => $text,
        ];
    }

    /** @return array<string, mixed> */
    private function executeSearch(array $config, PlaybookStep $step, Experiment $experiment): array
    {
        $context = $this->buildStepContext($step, $experiment);

        $queryTemplate = $config['query_template'] ?? '{{context.result}}';
        $query = $this->interpolate($queryTemplate, $context);
        $topK = (int) ($config['search_k'] ?? 5);

        $results = $this->memorySearch->execute(
            teamId: $experiment->team_id,
            query: $query,
            topK: $topK,
        );

        return [
            'query' => $query,
            'results' => $results->values()->toArray(),
            'result_count' => $results->count(),
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** @return array{string, string} */
    private function parseModel(string $modelString): array
    {
        if (str_contains($modelString, '/')) {
            [$provider, $model] = explode('/', $modelString, 2);

            return [$provider, $model];
        }

        return ['anthropic', $modelString];
    }

    /**
     * Build a PrismPHP ObjectSchema from an array schema definition.
     *
     * @param  array<string, array{type?: string, description?: string}>  $fields
     */
    private function buildObjectSchema(array $fields): ObjectSchema
    {
        $properties = [];
        $requiredFields = [];

        foreach ($fields as $name => $definition) {
            $type = $definition['type'] ?? 'string';
            $desc = $definition['description'] ?? '';

            $properties[] = match ($type) {
                'number', 'integer' => new NumberSchema($name, $desc),
                'boolean' => new BooleanSchema($name, $desc),
                'array' => new ArraySchema($name, $desc, new StringSchema('item', '')),
                default => new StringSchema($name, $desc),
            };

            $requiredFields[] = $name;
        }

        return new ObjectSchema(
            name: 'extracted_output',
            description: 'Structured output from LLM extraction',
            properties: $properties,
            requiredFields: $requiredFields,
        );
    }
}
