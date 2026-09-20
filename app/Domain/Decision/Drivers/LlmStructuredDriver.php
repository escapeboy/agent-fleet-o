<?php

namespace App\Domain\Decision\Drivers;

use App\Domain\Decision\Contracts\DecisionModel;
use App\Domain\Decision\DTOs\Answer;
use App\Domain\Decision\DTOs\ChoiceAnswer;
use App\Domain\Decision\DTOs\DecisionResult;
use App\Domain\Decision\DTOs\NoulAnswer;
use App\Domain\Decision\DTOs\ScoreAnswer;
use App\Domain\Decision\Exceptions\DecisionRequestException;
use App\Domain\Decision\Services\CredentialRedactor;
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
                systemPrompt: $this->systemPrompt($questions),
                userPrompt: $this->userPrompt($state),
                maxTokens: $this->maxTokens,
                outputSchema: $this->schema($questions),
                purpose: 'decision_eval',
                temperature: $this->temperature,
            ));
        } catch (Throwable $e) {
            throw new DecisionRequestException(
                CredentialRedactor::scrub('LLM decision call failed: '.$e->getMessage()),
            );
        }

        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $parsed = $response->parsedOutput ?? json_decode((string) $response->content, true);

        if (! is_array($parsed) || ! is_array($parsed['answers'] ?? null)) {
            throw new DecisionRequestException('LLM returned no parsable answers object.');
        }

        $byId = [];

        foreach ($parsed['answers'] as $entry) {
            if (is_array($entry) && isset($entry['id'])) {
                $byId[(string) $entry['id']] = $entry;
            }
        }

        $answers = [];

        foreach ($questions as $questionId => $question) {
            $entry = $byId[(string) $questionId] ?? null;

            if ($entry === null) {
                continue;
            }

            $answers[(string) $questionId] = $this->mapAnswer((string) ($question['type'] ?? 'choice'), $question, $entry);
        }

        return new DecisionResult(
            answers: $answers,
            model: $response->model,
            inputTokens: $response->usage->promptTokens,
            latencyMs: $latencyMs,
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $questions
     */
    private function systemPrompt(array $questions): string
    {
        $spec = [];

        foreach ($questions as $id => $question) {
            $spec[] = [
                'id' => (string) $id,
                'type' => $question['type'] ?? 'choice',
                'instructions' => $question['instructions'] ?? '',
                'criteria' => $question['criteria'] ?? null,
            ];
        }

        $json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
        You answer typed questions about a state. You do not explain, you do not add prose.

        Answer every question independently. Judge each one only against the state the
        user supplies — never against another question's answer.

        Question types:
        - choice: pick exactly one key from that question's criteria object.
        - score: return the index of the matching level in that question's criteria array, 0-based.
        - noul: return how likely the statement is to be true, from 0.0 to 1.0.

        For choice and score, also return `probabilities`: one entry per available
        option, using the option key (choice) or the level index as a string (score).
        They must sum to 1.0 and reflect how likely each option is, not how much you
        like it. If you are genuinely torn between two options, say so in the numbers.

        Questions:
        {$json}
        PROMPT;
    }

    private function userPrompt(array|string $state): string
    {
        return is_string($state)
            ? $state
            : (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
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

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $entry
     */
    private function mapAnswer(string $type, array $question, array $entry): Answer
    {
        $value = (string) ($entry['value'] ?? '');
        $probabilities = $this->normalizeProbabilities($entry['probabilities'] ?? null);
        $confidence = $probabilities === null ? null : max($probabilities);

        return match ($type) {
            'noul' => new NoulAnswer(noul: (float) $value),
            'score' => new ScoreAnswer(
                score: (float) $value,
                probabilities: $probabilities,
                confidence: $confidence,
                legend: $this->legend($question),
            ),
            default => new ChoiceAnswer(
                choice: $value,
                probabilities: $probabilities,
                confidence: $confidence,
            ),
        };
    }

    /**
     * @return array<string, float>|null
     */
    private function normalizeProbabilities(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $map = [];

        foreach ($raw as $item) {
            if (is_array($item) && isset($item['option'])) {
                $map[(string) $item['option']] = (float) ($item['probability'] ?? 0);
            }
        }

        $sum = array_sum($map);

        if ($map === [] || $sum <= 0) {
            return null;
        }

        return array_map(static fn (float $p): float => $p / $sum, $map);
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array<string, string>|null
     */
    private function legend(array $question): ?array
    {
        $criteria = $question['criteria'] ?? null;

        if (! is_array($criteria) || ! array_is_list($criteria)) {
            return null;
        }

        $legend = [];

        foreach ($criteria as $index => $level) {
            $legend[(string) $index] = is_string($level) ? $level : (string) json_encode($level);
        }

        return $legend;
    }
}
