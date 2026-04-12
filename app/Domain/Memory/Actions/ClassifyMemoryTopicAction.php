<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Memory\Enums\MemoryCategory;
use App\Domain\Memory\Models\Memory;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ClassifyMemoryTopicAction
{
    private const MODEL = 'claude-haiku-4-5-20251001';

    private const SYSTEM_PROMPT = 'You are a memory classifier. Return ONLY valid JSON, no explanation.';

    private const USER_PROMPT = <<<'PROMPT'
Classify this memory. Return JSON with exactly two fields:
- "topic": a snake_case slug (max 50 chars) for the context, e.g. "auth_migration", "checkout_flow", "database_design". Use null if no clear topic.
- "category": one of facts|events|discoveries|preferences|advice|knowledge|context|behavior|goal|preference

Memory:
%s
PROMPT;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    /**
     * Classify the memory's topic and optionally improve its category.
     * Updates $memory in-place. Fails silently — the memory remains valid without a topic.
     */
    public function execute(Memory $memory): void
    {
        if (! config('memory.enabled', true)) {
            return;
        }

        if (empty(trim($memory->content))) {
            return;
        }

        try {
            $response = $this->gateway->complete(new AiRequestDTO(
                provider: 'anthropic',
                model: self::MODEL,
                systemPrompt: self::SYSTEM_PROMPT,
                userPrompt: sprintf(self::USER_PROMPT, mb_substr($memory->content, 0, 500)),
                maxTokens: 128,
                teamId: $memory->team_id,
                purpose: 'memory.classify',
                temperature: 0.0,
            ));

            $result = json_decode(trim($response->content), true);

            if (! is_array($result)) {
                return;
            }

            $updates = [];

            if (isset($result['topic']) && is_string($result['topic'])) {
                $slug = $this->normalizeSlug($result['topic']);
                if ($slug !== '') {
                    $updates['topic'] = $slug;
                }
            }

            // Only update category if currently null
            if ($memory->category === null && isset($result['category'])) {
                $category = MemoryCategory::tryFrom($result['category']);
                if ($category !== null) {
                    $updates['category'] = $category;
                }
            }

            if (! empty($updates)) {
                $memory->update($updates);
            }
        } catch (\Throwable $e) {
            Log::debug('ClassifyMemoryTopicAction: classification failed', [
                'memory_id' => $memory->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function normalizeSlug(string $raw): string
    {
        // Normalize to snake_case slug, max 50 chars
        $slug = Str::snake(Str::ascii($raw));
        $slug = preg_replace('/[^a-z0-9_]/', '_', strtolower($slug)) ?? '';
        $slug = preg_replace('/_+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        return mb_substr($slug, 0, 50);
    }
}
