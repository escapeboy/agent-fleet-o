<?php

namespace App\Domain\Experiment\Services;

use App\Domain\Experiment\DTOs\DoneVerdict;
use App\Domain\Experiment\Models\Experiment;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\Log;

/**
 * Judge agent that re-evaluates an agent's "I'm done" claim against the
 * externalized feature-list.json done_criteria. Borrowed from Anthropic's
 * harness pattern: separating evaluator from generator stops the agent
 * from grading its own work too generously.
 *
 * Default model is Haiku 4.5 (cheap, fast, isolated). Per-Project override
 * lives in project.settings['done_gate_judge'] = {provider, model}.
 */
class DoneConditionJudge
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $features  feature-list.json features
     * @param  array<string, mixed>|string  $evidence  what the agent claims as proof of completion
     * @param  array{provider?: string, model?: string}|null  $override
     */
    public function evaluate(
        Experiment $experiment,
        array $features,
        array|string $evidence,
        ?array $override = null,
    ): DoneVerdict {
        $provider = $override['provider'] ?? 'anthropic';
        $model = $override['model'] ?? 'claude-haiku-4-5';

        $featuresJson = json_encode($features, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $evidenceText = is_string($evidence)
            ? $evidence
            : json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $systemPrompt = <<<'TXT'
        You are a strict completion judge. The worker agent claims this work is done.

        Reply ONLY with JSON: {"confirmed": bool, "reasoning": string, "missing": [string], "next_actions": [string]}.

        Be skeptical. If criteria mention tests, demand test output. If criteria mention deploy,
        demand a deploy URL. Do not accept "I think it works" or vague handwaving as evidence.
        Only confirm when EVERY done_criterion has concrete proof in the evidence.
        TXT;

        $userPrompt = "Done criteria (testable):\n{$featuresJson}\n\nEvidence the agent provided:\n{$evidenceText}";

        $request = new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: 1024,
            userId: $experiment->user_id,
            teamId: $experiment->team_id,
            purpose: 'judge.done',
        );

        try {
            $response = $this->gateway->complete($request);
        } catch (\Throwable $e) {
            Log::warning('DoneConditionJudge: gateway failed, defaulting to confirmed=false', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);

            return new DoneVerdict(
                confirmed: false,
                reasoning: 'Judge unreachable: '.$e->getMessage(),
                missing: ['Judge gateway error — manual review required'],
                nextActions: [],
                judgeModel: $model,
            );
        }

        $parsed = $response->parsedOutput;
        if (! is_array($parsed)) {
            $parsed = $this->extractJson($response->content);
        }

        if (! is_array($parsed)) {
            return new DoneVerdict(
                confirmed: false,
                reasoning: 'Judge response was not valid JSON.',
                missing: ['Judge output unparsable'],
                nextActions: [],
                judgeModel: $model,
            );
        }

        return new DoneVerdict(
            confirmed: (bool) ($parsed['confirmed'] ?? false),
            reasoning: (string) ($parsed['reasoning'] ?? ''),
            missing: array_values(array_map('strval', $parsed['missing'] ?? [])),
            nextActions: array_values(array_map('strval', $parsed['next_actions'] ?? [])),
            judgeModel: $model,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJson(string $content): ?array
    {
        $stripped = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($content));
        $decoded = json_decode((string) $stripped, true);

        return is_array($decoded) ? $decoded : null;
    }
}
