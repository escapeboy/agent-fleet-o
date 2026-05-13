<?php

namespace App\Domain\Agent\Services;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EquivocationDetector
{
    private const TTL_SECONDS = 86400; // 24 hours

    private const EQUIVOCATION_THRESHOLD = 3;

    /**
     * Record an agent response and check for equivocation.
     * Call this after any AiRun completes for an agent.
     *
     * Returns true if equivocation was detected.
     */
    public function record(Agent $agent, string $prompt, string $response): bool
    {
        $promptHash = hash('sha256', $prompt);
        $responseHash = hash('sha256', $response);
        $cacheKey = "agent_equivoc:{$agent->id}:{$promptHash}";

        $stored = Cache::store('default')->get($cacheKey);

        if ($stored === null) {
            Cache::store('default')->put($cacheKey, $responseHash, self::TTL_SECONDS);

            return false;
        }

        if ($stored === $responseHash) {
            return false;
        }

        // Different response for same prompt — equivocation detected
        $this->handleEquivocation($agent, $promptHash);

        // Update stored hash to latest
        Cache::store('default')->put($cacheKey, $responseHash, self::TTL_SECONDS);

        return true;
    }

    /**
     * Reset equivocation counter for an agent (call after manual review).
     */
    public function reset(Agent $agent): void
    {
        $agent->update([
            'equivocation_count' => 0,
            'last_equivocated_at' => null,
        ]);
    }

    private function handleEquivocation(Agent $agent, string $promptHash): void
    {
        $newCount = ($agent->equivocation_count ?? 0) + 1;

        $agent->update([
            'equivocation_count' => $newCount,
            'last_equivocated_at' => now(),
        ]);

        Log::warning('Agent equivocation detected', [
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'prompt_hash' => $promptHash,
            'equivocation_count' => $newCount,
        ]);

        if ($newCount >= self::EQUIVOCATION_THRESHOLD && $agent->status !== AgentStatus::Degraded) {
            $agent->update(['status' => AgentStatus::Degraded]);

            Log::error('Agent auto-degraded due to equivocation threshold', [
                'agent_id' => $agent->id,
                'agent_name' => $agent->name,
                'threshold' => self::EQUIVOCATION_THRESHOLD,
            ]);
        }
    }
}
