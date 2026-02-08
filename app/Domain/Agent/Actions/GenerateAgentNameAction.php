<?php

namespace App\Domain\Agent\Actions;

use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;

class GenerateAgentNameAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    /**
     * Generate a creative agent name using a lightweight LLM.
     * Falls back to a deterministic name if LLM call fails.
     */
    public function execute(
        string $role,
        string $goal,
        string $teamId,
        string $userId,
    ): string {
        try {
            $request = new AiRequestDTO(
                provider: 'anthropic',
                model: 'claude-haiku-4-5',
                systemPrompt: 'Generate a creative, memorable agent name. Return ONLY the name, nothing else. The name should be 1-3 words, professional, and relate to the agent\'s role.',
                userPrompt: "Role: {$role}\nGoal: {$goal}",
                maxTokens: 50,
                userId: $userId,
                teamId: $teamId,
                purpose: 'agent-name-generation',
                temperature: 0.9,
            );

            $response = $this->gateway->complete($request);
            $name = trim($response->content);

            // Validate: should be short and clean
            if (strlen($name) > 0 && strlen($name) <= 60) {
                return $name;
            }
        } catch (\Throwable) {
            // Fall through to fallback
        }

        // Fallback: deterministic name
        $shortRole = ucfirst(strtolower(substr($role, 0, 20)));

        return "{$shortRole} Agent #" . random_int(1000, 9999);
    }
}
