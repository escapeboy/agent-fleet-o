<?php

namespace App\Domain\Decision\Drivers;

use App\Domain\Decision\Contracts\DecisionModel;
use App\Domain\Decision\DTOs\DecisionResult;
use App\Domain\Decision\Exceptions\DecisionRequestException;
use App\Domain\Decision\Services\CredentialRedactor;
use App\Domain\Decision\Services\StructuredDecisionPrompt;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Throwable;

/**
 * The same DecisionModel contract served by an ordinary chat LLM through the
 * platform's AI gateway, so an eval can put Jev and (say) Haiku 4.5 on the same
 * dataset with the same scoring code.
 *
 * Two things differ from a System One model and the numbers must be read with
 * both in mind:
 *  - Probabilities here are *verbalized* — the model is asked to report a
 *    distribution, it is not a read-out of the decoder. They are not calibrated
 *    the way Jev's are, which is exactly what the ECE column is measuring.
 *  - A provider that returns nothing usable leaves probabilities and confidence
 *    null, and the coverage sweep simply has no score to threshold on.
 */
class LlmStructuredDriver implements DecisionModel
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly string $provider,
        private readonly string $model,
        private readonly int $maxTokens = 2048,
        private readonly float $temperature = 0.0,
        /**
         * The gateway logs every call to llm_request_logs, which is tenant-scoped
         * and rejects a null team. An eval has no tenant of its own, so the run
         * borrows one — see the --team option on jev:eval.
         */
        private readonly ?string $teamId = null,
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function decide(array|string $state, array $questions): DecisionResult
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $this->provider,
                model: $this->model,
                systemPrompt: StructuredDecisionPrompt::system($questions),
                userPrompt: StructuredDecisionPrompt::user($state),
                maxTokens: $this->maxTokens,
                outputSchema: $this->schema($questions),
                teamId: $this->teamId,
                purpose: 'decision_eval',
                temperature: $this->temperature,
            ));
        } catch (Throwable $e) {
            throw new DecisionRequestException(
                CredentialRedactor::scrub('LLM decision call failed: '.$e->getMessage()),
            );
        }

        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $parsed = $response->parsedOutput
            ?? StructuredDecisionPrompt::extractFirstJsonObject((string) $response->content);

        if (! is_array($parsed) || ! is_array($parsed['answers'] ?? null)) {
            throw new DecisionRequestException('LLM returned no parsable answers object.');
        }

        $answers = StructuredDecisionPrompt::answers($parsed, $questions);

        return new DecisionResult(
            answers: $answers,
            model: $response->model,
            inputTokens: $response->usage->promptTokens,
            latencyMs: $latencyMs,
        );
    }

    /**
     * Prism-specific: the structured-output schema the gateway enforces. The
     * `claude -p` driver has no equivalent — it asks for the same shape in
     * words and parses what comes back.
     *
     * @param  array<string, array<string, mixed>>  $questions
     */
    private function schema(array $questions): ObjectSchema
    {
        $answer = new ObjectSchema(
            name: 'answer',
            description: 'One answer, keyed back to the question id it belongs to',
            properties: [
                new StringSchema('id', 'The question id being answered'),
                new StringSchema('value', 'choice: the chosen option key. score: the level index as a string. noul: a number 0.0-1.0 as a string.'),
                new ArraySchema(
                    name: 'probabilities',
                    description: 'One entry per option; omit entirely for noul questions',
                    items: new ObjectSchema(
                        name: 'option_probability',
                        description: 'Probability of a single option',
                        properties: [
                            new StringSchema('option', 'The option key, or the level index as a string'),
                            new NumberSchema('probability', 'Probability of this option, 0.0-1.0'),
                        ],
                        requiredFields: ['option', 'probability'],
                    ),
                    nullable: true,
                ),
            ],
            requiredFields: ['id', 'value'],
        );

        return new ObjectSchema(
            name: 'decision',
            description: 'One entry per question, in the order the questions were given',
            properties: [
                new ArraySchema(
                    name: 'answers',
                    description: 'Exactly '.count($questions).' answers',
                    items: $answer,
                ),
            ],
            requiredFields: ['answers'],
        );
    }
}
