<?php

namespace App\Domain\Chatbot\Services;

use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Domain\Shared\Models\Team;
use Barsy\Contracts\MemoryContextProviderInterface;
use Illuminate\Support\Facades\Log;

class ChatbotMemoryContextProvider implements MemoryContextProviderInterface
{
    private const CURATED_TIERS = ['canonical', 'facts', 'decisions', 'failures', 'successes'];

    public function __construct(
        private readonly RetrieveRelevantMemoriesAction $retrieveMemories,
    ) {}

    public function retrieveContext(string $query, string $role, ?string $chatbotId = null): ?string
    {
        if (! config('memory.enabled', true)) {
            return null;
        }

        $chatbot = $chatbotId ? Chatbot::find($chatbotId) : null;
        $agentId = $chatbot?->agent_id;
        $teamId = $chatbot?->team_id ?? Team::first()?->id;

        if (! $teamId) {
            return null;
        }

        $tags = ["barsy:{$role}", 'barsy:shared'];
        $topK = (int) config('chat.memory.max_results', 5);

        try {
            $memories = $this->retrieveMemories->execute(
                agentId: $agentId ?? 'barsy-chatbot',
                query: $query,
                topK: $topK,
                scope: $agentId ? 'agent' : 'team',
                teamId: $teamId,
                minConfidence: 0.5,
                tags: $tags,
            );

            // Filter to curated tiers only when configured
            if (config('chat.memory.require_curated', true)) {
                $memories = $memories->filter(
                    fn ($m) => in_array($m->tier, self::CURATED_TIERS, true),
                );
            }

            if ($memories->isEmpty()) {
                return null;
            }

            $parts = $memories->map(fn ($m) => "- {$m->content}")->implode("\n");

            return "## Learned Facts (from past conversations)\n\n{$parts}";
        } catch (\Throwable $e) {
            Log::warning('ChatbotMemoryContextProvider: retrieval failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
