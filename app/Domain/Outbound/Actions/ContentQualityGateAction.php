<?php

declare(strict_types=1);

namespace App\Domain\Outbound\Actions;

use App\Domain\Outbound\Exceptions\ContentQualityException;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\BrandVoiceValidator;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\Log;

/**
 * Pre-send content quality gate (dotCMS-borrowed "Content Quality Agent").
 *
 * Surfaces brand-voice and quality issues on outbound content before it is
 * delivered. Two checks:
 *  1. deterministic brand-voice validation (always, free); and
 *  2. an optional LLM judge ({@see https://...}, opt-in via `llm_check`).
 *
 * Configured per team at `team.settings['content_gate']`:
 *   enabled:    master switch (default false — no behaviour change until opted in)
 *   mode:       warn | block (default warn)
 *   llm_check:  run the LLM judge (default false — avoids per-send cost)
 *   min_score:  judge pass threshold 0..1 (default 0.6)
 *   judge_model "provider/model" for the judge (default anthropic/claude-haiku-4-5)
 */
class ContentQualityGateAction
{
    public function __construct(
        private readonly BrandVoiceValidator $brandVoice,
        private readonly AiGatewayInterface $gateway,
    ) {}

    /**
     * Enforce the gate for an outbound proposal. Returns null when disabled,
     * otherwise the evaluation result. Throws {@see ContentQualityException}
     * when the gate is in `block` mode and the content fails.
     */
    public function guard(OutboundProposal $proposal): ?ContentQualityResult
    {
        $config = $this->configFor($proposal->team_id);
        if (! $config['enabled']) {
            return null;
        }

        $text = $this->extractText($proposal->content);
        if ($text === '') {
            return new ContentQualityResult(passed: true);
        }

        $result = $this->execute($text, $proposal->team_id);
        if ($result->passed) {
            return $result;
        }

        if ($config['mode'] === 'block') {
            throw new ContentQualityException(
                'Outbound content blocked by quality gate: '.implode(' ', $result->reasons()),
                $result->reasons(),
            );
        }

        Log::warning('Outbound content quality gate flagged a proposal', [
            'proposal_id' => $proposal->id,
            'team_id' => $proposal->team_id,
            'score' => $result->score,
            'reasons' => $result->reasons(),
        ]);

        return $result;
    }

    /**
     * Evaluate text against brand voice (deterministic) and, when enabled,
     * an LLM quality judge. Pure — never throws, never logs.
     */
    public function execute(string $text, ?string $teamId): ContentQualityResult
    {
        $config = $this->configFor($teamId);

        $brand = $this->brandVoice->validate($text, $teamId);

        $score = null;
        $issues = [];
        if ($config['llm_check']) {
            [$score, $issues] = $this->judge($text, $teamId, $config);
        }

        $qualityFailed = $score !== null && $score < $config['min_score'];

        return new ContentQualityResult(
            passed: $brand->passed && ! $qualityFailed,
            score: $score,
            brandViolations: $brand->violations,
            qualityIssues: $issues,
        );
    }

    /**
     * @param  array{enabled: bool, mode: string, llm_check: bool, min_score: float, judge_model: string}  $config
     * @return array{0: float|null, 1: list<string>}
     */
    private function judge(string $text, ?string $teamId, array $config): array
    {
        [$provider, $model] = $this->resolveModel($config['judge_model']);

        try {
            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $provider,
                model: $model,
                systemPrompt: 'You are an impartial content quality reviewer. Assess clarity, grammar, and professionalism. Always return valid JSON: {"score": 0.0-1.0, "issues": ["string", ...]}. Score 1.0 = excellent, 0.0 = unusable.',
                userPrompt: "Review this outbound message:\n\n".$text,
                maxTokens: 512,
                teamId: $teamId,
                purpose: 'content_quality_gate',
                temperature: 0.1,
            ));

            $parsed = $this->extractJson($response->content);
            if ($parsed === null) {
                return [null, []];
            }

            $score = isset($parsed['score']) ? min(1.0, max(0.0, (float) $parsed['score'])) : null;
            $issues = [];
            foreach (is_array($parsed['issues'] ?? null) ? $parsed['issues'] : [] as $issue) {
                if (is_string($issue) && trim($issue) !== '') {
                    $issues[] = trim($issue);
                }
            }

            return [$score, $issues];
        } catch (\Throwable $e) {
            // Fail-open: a judge outage must never block a legitimate send.
            Log::warning('Content quality judge failed; treating as pass', [
                'team_id' => $teamId,
                'error' => $e->getMessage(),
            ]);

            return [null, []];
        }
    }

    /**
     * @return array{enabled: bool, mode: string, llm_check: bool, min_score: float, judge_model: string}
     */
    private function configFor(?string $teamId): array
    {
        $raw = [];
        if ($teamId !== null) {
            $settings = Team::withoutGlobalScopes()->find($teamId)?->settings['content_gate'] ?? [];
            $raw = is_array($settings) ? $settings : [];
        }

        $mode = ($raw['mode'] ?? 'warn') === 'block' ? 'block' : 'warn';

        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'mode' => $mode,
            'llm_check' => (bool) ($raw['llm_check'] ?? false),
            'min_score' => isset($raw['min_score']) ? min(1.0, max(0.0, (float) $raw['min_score'])) : 0.6,
            'judge_model' => is_string($raw['judge_model'] ?? null) && $raw['judge_model'] !== ''
                ? $raw['judge_model']
                : 'anthropic/claude-haiku-4-5',
        ];
    }

    /**
     * Join the string scalars of a proposal's content payload into one blob.
     *
     * @param  mixed  $content
     */
    private function extractText($content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (! is_array($content)) {
            return '';
        }

        $parts = [];
        array_walk_recursive($content, static function ($value) use (&$parts): void {
            if (is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);
            }
        });

        return trim(implode("\n", $parts));
    }

    /**
     * Tolerant JSON extraction — local agents emit markdown fences and prose.
     *
     * @return array<string, mixed>|null
     */
    private function extractJson(string $raw): ?array
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveModel(string $judgeModel): array
    {
        if (str_contains($judgeModel, '/')) {
            [$provider, $model] = explode('/', $judgeModel, 2);

            return [$provider, $model];
        }

        if (str_starts_with($judgeModel, 'gpt')) {
            return ['openai', $judgeModel];
        }

        return ['anthropic', $judgeModel];
    }
}
