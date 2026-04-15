<?php

namespace App\Domain\Memory\Actions;

use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ClassifyQueryTopicAction
{
    private const MODEL = 'claude-haiku-4-5-20251001';

    private const SYSTEM_PROMPT = 'You are a memory classifier. Return ONLY valid JSON, no explanation.';

    private const USER_PROMPT = <<<'PROMPT'
Classify this query. Return JSON with one field:
- "topic": a snake_case slug (max 50 chars) for the context, e.g. "auth_migration", "checkout_flow", "database_design". Use null if no clear topic.

Query:
%s
PROMPT;

    private const CACHE_TTL = 3600;

    private const MAX_QUERY_LENGTH = 500;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    /**
     * Classify a free-text query into a snake_case topic slug.
     * Returns null when the query is empty, classification fails, or no clear topic exists.
     * Caches by SHA-256 hash of the normalised query for 1 hour (Redis).
     */
    public function execute(string $query, ?string $teamId = null): ?string
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        $cacheKey = 'memory:query_topic:'.hash('sha256', mb_strtolower($query));
        $startTime = hrtime(true);
        $gatewayInvoked = false;

        $topic = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($query, $teamId, &$gatewayInvoked) {
            $gatewayInvoked = true;

            try {
                $response = $this->gateway->complete(new AiRequestDTO(
                    provider: 'anthropic',
                    model: self::MODEL,
                    systemPrompt: self::SYSTEM_PROMPT,
                    userPrompt: sprintf(self::USER_PROMPT, mb_substr($query, 0, self::MAX_QUERY_LENGTH)),
                    maxTokens: 64,
                    teamId: $teamId,
                    purpose: 'memory.classify_query',
                    temperature: 0.0,
                ));

                $result = json_decode(trim($response->content), true);
                if (! is_array($result) || empty($result['topic']) || ! is_string($result['topic'])) {
                    return null;
                }

                return $this->normalizeSlug($result['topic']) ?: null;
            } catch (\Throwable $e) {
                Log::debug('ClassifyQueryTopicAction: classification failed', [
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });

        $latencyMs = (int) round((hrtime(true) - $startTime) / 1_000_000);

        Log::info('memory.topic_classified', [
            'query_hash' => hash('sha256', mb_strtolower($query)),
            'topic' => $topic,
            'cache_hit' => ! $gatewayInvoked,
            'latency_ms' => $latencyMs,
            'team_id' => $teamId,
        ]);

        return $topic;
    }

    private function normalizeSlug(string $raw): string
    {
        $slug = Str::snake(Str::ascii($raw));
        $slug = preg_replace('/[^a-z0-9_]/', '_', strtolower($slug)) ?? '';
        $slug = preg_replace('/_+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        return mb_substr($slug, 0, 50);
    }
}
