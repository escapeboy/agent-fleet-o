<?php

namespace App\Domain\Memory\Services;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Support\Facades\Log;

/**
 * Deep (tier-2) relevance judgment over already-retrieved memories — the
 * "judge" stage borrowed from contextrie's shallow→deep cascade.
 *
 * Tier-1 (RetrieveRelevantMemoriesAction) ranks by embedding similarity +
 * composite metadata score and caps to top-K. That is cheap and recall-biased:
 * a memory can be semantically near the query yet irrelevant to *this* task.
 * This stage asks a cheap LLM to score each survivor 0..1 for task relevance
 * and drops the sub-threshold ones, so fewer (and tighter) memories reach the
 * expensive main agent call.
 *
 * Cost-bounded: one LLM call per injection, opt-in (default off), and only when
 * the candidate set is large enough to be worth filtering. Fails OPEN — any
 * error keeps the original candidate set, never blanks the context.
 */
class MemoryRelevanceJudge
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You score how relevant each stored memory is to the user's current task.

Score each numbered memory from 0.0 (irrelevant to this task) to 1.0 (directly
useful for completing this task). Judge task-relevance, not general quality: a
true but off-topic fact scores low.

Return ONLY valid JSON (no markdown fences):
{"scores": [{"n": 1, "score": 0.9}, {"n": 2, "score": 0.2}]}

Include every memory number exactly once.
PROMPT;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
    ) {}

    /**
     * Return the subset of candidate ids judged relevant (score >= threshold).
     *
     * Fails open: on any error, malformed output, or missing actor, the full
     * set of candidate ids is returned so memory context is never silently lost.
     *
     * @param  array<int, array{id: string, content: string}>  $candidates
     * @return list<string> Kept candidate ids, original order preserved.
     */
    public function judge(string $query, array $candidates, string $teamId, ?string $userId = null): array
    {
        $allIds = array_values(array_map(fn (array $c) => $c['id'], $candidates));

        if ($candidates === []) {
            return [];
        }

        $userId = $userId ?? Team::ownerIdFor($teamId);
        if (! $userId) {
            return $allIds;
        }

        $threshold = (float) config('memory.deep_judgment.threshold', 0.5);

        // LLM speaks 1-based numbers; map them back to candidate ids afterwards.
        $lines = [];
        foreach ($candidates as $i => $c) {
            $number = $i + 1;
            $content = mb_strimwidth((string) $c['content'], 0, 500, '…');
            $lines[] = "Memory {$number}:\n{$content}";
        }

        try {
            $team = Team::find($teamId);
            $resolved = $this->providerResolver->resolve(team: $team);

            // The model carries its own provider prefix ("anthropic/claude-haiku-4-5")
            // so the model name is never paired with a foreign resolved provider
            // (which 400s on gateways that don't expose Anthropic models). An
            // un-prefixed override falls back to the team-resolved provider.
            $configured = (string) config('memory.deep_judgment.model', 'anthropic/claude-haiku-4-5');
            [$provider, $model] = str_contains($configured, '/')
                ? explode('/', $configured, 2)
                : [$resolved['provider'], $configured];

            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $provider,
                model: $model,
                systemPrompt: self::SYSTEM_PROMPT,
                userPrompt: "TASK:\n{$query}\n\nMEMORIES:\n".implode("\n\n", $lines),
                maxTokens: 512,
                userId: $userId,
                teamId: $teamId,
                purpose: 'memory.deep_judgment',
                temperature: 0.0,
            ));

            $scores = $this->parseScores($response->content);
            if ($scores === null) {
                return $allIds;
            }

            $kept = [];
            foreach ($candidates as $i => $c) {
                $number = $i + 1;
                // Unscored memory defaults to kept (fail-open per item).
                $score = $scores[$number] ?? 1.0;
                if ($score >= $threshold) {
                    $kept[] = $c['id'];
                }
            }

            // Never blank the context: if the judge rejected everything, keep the
            // original set rather than injecting nothing.
            return $kept === [] ? $allIds : $kept;
        } catch (\Throwable $e) {
            Log::warning('MemoryRelevanceJudge: judging failed, keeping all candidates', [
                'team_id' => $teamId,
                'error' => $e->getMessage(),
            ]);

            return $allIds;
        }
    }

    /**
     * Parse the judge response into a [number => score] map.
     *
     * @return array<int, float>|null Null when the payload is unusable.
     */
    private function parseScores(string $content): ?array
    {
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*\n?/', '', $content);
            $content = preg_replace('/\n?```\s*$/', '', (string) $content);
        }

        $decoded = json_decode((string) $content, true);
        if (! is_array($decoded) || ! isset($decoded['scores']) || ! is_array($decoded['scores'])) {
            return null;
        }

        $map = [];
        foreach ($decoded['scores'] as $entry) {
            if (! is_array($entry) || ! isset($entry['n'])) {
                continue;
            }
            $map[(int) $entry['n']] = (float) ($entry['score'] ?? 1.0);
        }

        return $map;
    }
}
