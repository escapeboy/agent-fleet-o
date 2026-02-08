<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\Log;

class HealthCheckAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    public function execute(Agent $agent): bool
    {
        try {
            $request = new AiRequestDTO(
                provider: $agent->provider,
                model: $agent->model,
                systemPrompt: 'You are a health check responder.',
                userPrompt: 'Respond with "ok".',
                maxTokens: 10,
                temperature: 0.0,
            );

            $response = $this->gateway->complete($request);

            $agent->update([
                'status' => AgentStatus::Active,
                'last_health_check' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning("Agent health check failed: {$agent->name}", [
                'agent_id' => $agent->id,
                'provider' => $agent->provider,
                'error' => $e->getMessage(),
            ]);

            $agent->update([
                'status' => AgentStatus::Degraded,
                'last_health_check' => now(),
            ]);

            return false;
        }
    }
}
