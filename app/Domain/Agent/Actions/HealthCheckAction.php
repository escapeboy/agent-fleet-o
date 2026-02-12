<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use Illuminate\Support\Facades\Log;

class HealthCheckAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly LocalAgentDiscovery $discovery,
    ) {}

    public function execute(Agent $agent): bool
    {
        if (config("llm_providers.{$agent->provider}.local")) {
            return $this->checkLocalAgent($agent);
        }

        return $this->checkCloudAgent($agent);
    }

    private function checkLocalAgent(Agent $agent): bool
    {
        $agentKey = config("llm_providers.{$agent->provider}.agent_key", $agent->provider);

        if (! $this->discovery->isAvailable($agentKey)) {
            Log::warning("Local agent health check: binary not found for {$agent->name}", [
                'agent_id' => $agent->id,
                'agent_key' => $agentKey,
            ]);

            $agent->update([
                'status' => AgentStatus::Offline,
                'last_health_check' => now(),
            ]);

            return false;
        }

        // Binary exists — mark as active
        $agent->update([
            'status' => AgentStatus::Active,
            'last_health_check' => now(),
        ]);

        return true;
    }

    private function checkCloudAgent(Agent $agent): bool
    {
        try {
            $request = new AiRequestDTO(
                provider: $agent->provider,
                model: $agent->model,
                systemPrompt: 'You are a health check responder.',
                userPrompt: 'Respond with "ok".',
                maxTokens: 10,
                agentId: $agent->id,
                teamId: $agent->team_id,
                purpose: 'health_check',
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
