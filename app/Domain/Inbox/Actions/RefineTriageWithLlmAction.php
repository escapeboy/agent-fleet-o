<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Inbox\DTOs\LlmTriageVerdictDTO;
use App\Domain\Inbox\Models\InboxTriageResult;
use App\Domain\Inbox\Services\TriagePromptBuilder;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Livewire\Inbox\Services\InboxTriageScorer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * LLM-driven triage refinement. Calls AiGateway with team-resolved provider,
 * caches result for 1h, and stores it in inbox_triage_results for feedback learning.
 *
 * Falls back to heuristic-only when:
 *  - No provider available for team
 *  - LLM call fails (timeout, schema validation, parse error)
 *  - Cost cap (100 calls/team/day) exceeded
 */
class RefineTriageWithLlmAction
{
    private const COST_CAP_PER_DAY = 100;

    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
        private readonly TriagePromptBuilder $prompts,
        private readonly InboxTriageScorer $heuristic,
    ) {}

    public function execute(ApprovalRequest|OutboundProposal $item): LlmTriageVerdictDTO
    {
        $teamId = $item->team_id;
        $kind = $item instanceof ApprovalRequest
            ? ($item->isHumanTask() ? 'human_task' : 'approval')
            : 'proposal';

        // Cache hit fast-path
        $cacheKey = $this->cacheKey($teamId, $kind, $item->id);
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return new LlmTriageVerdictDTO(
                score: (float) $cached['score'],
                recommendation: $cached['rec'],
                reason: $cached['reason'],
                fromCache: true,
            );
        }

        // Cost cap
        if ($this->dailyCallCount($teamId) >= self::COST_CAP_PER_DAY) {
            return $this->heuristicFallback($item, 'cost cap reached');
        }

        // Resolve provider for team — fall back if none configured
        $team = Team::find($teamId);
        if (! $team) {
            return $this->heuristicFallback($item, 'team not found');
        }

        try {
            [$provider, $model] = $this->providerResolver->resolve(team: $team, purpose: 'inbox_triage');
        } catch (\Throwable) {
            return $this->heuristicFallback($item, 'no provider available');
        }

        // Build prompt
        $heuristicScore = $item instanceof ApprovalRequest
            ? $this->heuristic->scoreApproval($item)
            : $this->heuristic->scoreProposal($item);

        $userPrompt = $item instanceof ApprovalRequest
            ? $this->prompts->approvalUserPrompt($item, $heuristicScore, $teamId)
            : $this->prompts->proposalUserPrompt($item, $heuristicScore, $teamId);

        // Call LLM
        try {
            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $provider,
                model: $model,
                systemPrompt: $this->prompts->systemPrompt(),
                userPrompt: $userPrompt,
                maxTokens: 200,
                userId: $item->team->owner_id ?? null,
                teamId: $teamId,
                purpose: 'inbox_triage',
                temperature: 0.2,
            ));
        } catch (\Throwable $e) {
            return $this->heuristicFallback($item, 'gateway error: '.substr($e->getMessage(), 0, 100));
        }

        $parsed = $this->parseVerdict($response->content ?? '');
        if ($parsed === null) {
            return $this->heuristicFallback($item, 'invalid LLM JSON');
        }

        // Increment cost counter (Redis 24h TTL)
        $this->incrementDailyCallCount($teamId);

        // Persist for feedback learning
        InboxTriageResult::updateOrCreate(
            ['team_id' => $teamId, 'source_kind' => $kind, 'source_id' => $item->id],
            [
                'llm_score' => $parsed['score'],
                'llm_recommendation' => $parsed['rec'],
                'llm_reason' => $parsed['reason'],
            ],
        );

        Cache::put($cacheKey, $parsed, self::CACHE_TTL_SECONDS);

        return new LlmTriageVerdictDTO(
            score: (float) $parsed['score'],
            recommendation: $parsed['rec'],
            reason: $parsed['reason'],
        );
    }

    private function heuristicFallback(ApprovalRequest|OutboundProposal $item, string $degradeReason): LlmTriageVerdictDTO
    {
        $score = $item instanceof ApprovalRequest
            ? $this->heuristic->scoreApproval($item)
            : $this->heuristic->scoreProposal($item);
        $rec = $this->heuristic->recommendation($score);

        return new LlmTriageVerdictDTO(
            score: $score,
            recommendation: $rec,
            reason: '[heuristic-only] '.$degradeReason,
            fromCache: false,
        );
    }

    /**
     * @return ?array{score: float, rec: string, reason: string}
     */
    private function parseVerdict(string $content): ?array
    {
        $stripped = preg_replace('/```(?:json)?\s*|\s*```/', '', $content);
        $start = strpos($stripped, '{');
        $end = strrpos($stripped, '}');

        if ($start === false || $end === false || $start >= $end) {
            return null;
        }

        $json = substr($stripped, $start, $end - $start + 1);
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return null;
        }

        $score = $decoded['score'] ?? null;
        $rec = $decoded['rec'] ?? null;
        $reason = $decoded['reason'] ?? null;

        if (! is_numeric($score) || ! is_string($rec) || ! is_string($reason)) {
            return null;
        }

        if (! in_array($rec, ['review_now', 'review_soon', 'low_priority'], true)) {
            return null;
        }

        return [
            'score' => max(0.0, min(1.0, (float) $score)),
            'rec' => $rec,
            'reason' => mb_substr($reason, 0, 280),
        ];
    }

    private function cacheKey(string $teamId, string $kind, string $sourceId): string
    {
        return "inbox_triage:{$teamId}:{$kind}:{$sourceId}";
    }

    private function dailyCallCount(string $teamId): int
    {
        return (int) Redis::get($this->dailyCounterKey($teamId)) ?: 0;
    }

    private function incrementDailyCallCount(string $teamId): void
    {
        $key = $this->dailyCounterKey($teamId);
        Redis::incr($key);
        Redis::expire($key, 86400);
    }

    private function dailyCounterKey(string $teamId): string
    {
        return "inbox_triage_count:{$teamId}:".now()->toDateString();
    }
}
