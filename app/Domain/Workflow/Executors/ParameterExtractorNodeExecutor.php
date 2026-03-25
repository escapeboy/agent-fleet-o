<?php

namespace App\Domain\Workflow\Executors;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Contracts\NodeExecutorInterface;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * Extracts structured fields from unstructured text using an LLM with forced JSON output.
 *
 * Config shape:
 * {
 *   "model": "anthropic/claude-haiku-4-5",
 *   "schema": {
 *     "company_name": {"type": "string"},
 *     "invoice_date": {"type": "string"},
 *     "total_amount": {"type": "number"},
 *     "is_paid": {"type": "boolean"}
 *   },
 *   "input_template": "Extract fields from:\n\n{{context}}"
 * }
 */
class ParameterExtractorNodeExecutor implements NodeExecutorInterface
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

        $inputTemplate = $config['input_template'] ?? 'Extract structured data from the following text:\n\n{{context}}';
        $prompt = $this->interpolate($inputTemplate, $context);

        $schemaFields = $config['schema'] ?? [];
        $outputSchema = $this->buildObjectSchema($schemaFields);

        $response = $this->gateway->complete(new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: 'You are a precise data extraction assistant. Extract only the requested fields from the provided text. Return null for any field you cannot find.',
            userPrompt: $prompt,
            maxTokens: (int) ($config['max_tokens'] ?? 512),
            outputSchema: $outputSchema,
            temperature: 0.0,
            teamId: $experiment->team_id,
            experimentId: $experiment->id,
            purpose: 'parameter_extractor',
        ));

        $extracted = is_array($response->structured) ? $response->structured : [];

        return [
            'extracted' => $extracted,
            ...$extracted, // also spread fields to top level for easy access
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

    /**
     * Build a PrismPHP ObjectSchema from the user-defined schema config.
     *
     * @param  array<string, array{type: string}>  $fields
     */
    private function buildObjectSchema(array $fields): ObjectSchema
    {
        $properties = [];
        $requiredFields = [];

        foreach ($fields as $name => $definition) {
            $type = $definition['type'] ?? 'string';

            $properties[] = match ($type) {
                'number', 'integer' => new NumberSchema($name, $definition['description'] ?? ''),
                'boolean' => new BooleanSchema($name, $definition['description'] ?? ''),
                'array' => new ArraySchema($name, $definition['description'] ?? '', new StringSchema('item', '')),
                default => new StringSchema($name, $definition['description'] ?? ''),
            };

            $requiredFields[] = $name;
        }

        return new ObjectSchema(
            name: 'extracted_parameters',
            description: 'Extracted structured fields',
            properties: $properties,
            requiredFields: $requiredFields,
        );
    }
}
