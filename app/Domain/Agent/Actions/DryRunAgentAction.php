<?php

declare(strict_types=1);

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Services\AgentPromptCompiler;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Run an agent through a single LLM completion without persisting an
 * AgentExecution row, AiRun row, or any artifacts. Lets customers test
 * a prompt change against an example input before promoting it to a
 * real run.
 *
 * Lean P1 cut of the broader "agent dry-run / replay" feature: no tool
 * loop, no skill chaining, no checkpoint/replay. Just the agent's
 * system prompt + a user message → one model call → one response.
 *
 * Tenant-safety:
 *   - Agent lookup honors team scope (caller passes $teamId).
 *   - Block agents that are PUBLISHED to the marketplace; running them
 *     in dry-run mode bypasses the buyer's licence terms.
 *   - The AI Gateway middleware pipeline still applies — budget,
 *     rate-limiting, idempotency, semantic cache, usage tracking.
 *
 * @phpstan-type DryRunResult array{
 *   agent_id: string,
 *   output: string,
 *   model: string,
 *   provider: string,
 *   latency_ms: int,
 *   cost_credits: int,
 *   tokens_input: int,
 *   tokens_output: int,
 *   system_prompt_used: string,
 *   marketplace_listed: bool,
 * }
 */
final class DryRunAgentAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly AgentPromptCompiler $promptCompiler,
    ) {}

    /**
     * @return DryRunResult
     */
    public function execute(
        Agent $agent,
        string $userMessage,
        string $userId,
        ?string $systemPromptOverride = null,
        ?string $modelOverride = null,
        ?float $temperatureOverride = null,
    ): array {
        if ($userMessage === '') {
            throw new InvalidArgumentException('Dry-run input message cannot be empty.');
        }

        $this->guardMarketplacePublished($agent);

        $systemPrompt = $systemPromptOverride !== null && $systemPromptOverride !== ''
            ? $systemPromptOverride
            : $this->promptCompiler->compile($agent);

        $provider = (string) ($agent->provider ?? config('llm.default_provider', 'anthropic'));
        $model = $modelOverride !== null && $modelOverride !== ''
            ? $modelOverride
            : (string) ($agent->model ?? config('llm.default_model', 'claude-haiku-4-5'));

        // Clamp temperature to a defensible range; 0.0–2.0 covers every supported provider.
        $temperature = $temperatureOverride !== null
            ? max(0.0, min(2.0, $temperatureOverride))
            : (float) (config('agent.default_temperature', 0.7));

        $request = new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: $systemPrompt,
            userPrompt: $userMessage,
            maxTokens: 4096,
            userId: $userId,
            teamId: (string) $agent->team_id,
            agentId: $agent->id,
            purpose: 'agent.dry_run',
            temperature: $temperature,
        );

        $response = $this->gateway->complete($request);
        $latencyMs = $response->latencyMs;

        Log::info('agent.dry_run', [
            'agent_id' => $agent->id,
            'team_id' => $agent->team_id,
            'user_id' => $userId,
            'provider' => $response->provider,
            'model' => $response->model,
            'latency_ms' => $latencyMs,
            'cost_credits' => $response->usage->costCredits,
            'tokens_input' => $response->usage->promptTokens,
            'tokens_output' => $response->usage->completionTokens,
            'override_used' => $systemPromptOverride !== null,
        ]);

        try {
            $ocsf = OcsfMapper::classify('agent.dry_run');
            AuditEntry::create([
                'team_id' => $agent->team_id,
                'user_id' => $userId,
                'event' => 'agent.dry_run',
                'ocsf_class_uid' => $ocsf['class_uid'],
                'ocsf_severity_id' => $ocsf['severity_id'],
                'subject_type' => Agent::class,
                'subject_id' => $agent->id,
                'properties' => [
                    'provider' => $response->provider,
                    'model' => $response->model,
                    'latency_ms' => $latencyMs,
                    'cost_credits' => $response->usage->costCredits,
                    'tokens_input' => $response->usage->promptTokens,
                    'tokens_output' => $response->usage->completionTokens,
                    'override_used' => $systemPromptOverride !== null,
                    'input_length' => mb_strlen($userMessage),
                ],
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Audit logging failures must not break the dry-run response.
        }

        return [
            'agent_id' => $agent->id,
            'output' => $response->content,
            'model' => $response->model,
            'provider' => $response->provider,
            'latency_ms' => $latencyMs,
            'cost_credits' => $response->usage->costCredits,
            'tokens_input' => $response->usage->promptTokens,
            'tokens_output' => $response->usage->completionTokens,
            'system_prompt_used' => $systemPrompt,
            'marketplace_listed' => false,
        ];
    }

    private function guardMarketplacePublished(Agent $agent): void
    {
        $listed = MarketplaceListing::withoutGlobalScopes()
            ->where('listable_id', $agent->id)
            ->where('type', 'agent')
            ->whereNotIn('status', ['draft', 'archived'])
            ->exists();

        if ($listed) {
            throw new InvalidArgumentException(
                'Agent is published to the marketplace and cannot be dry-run; clone it first.',
            );
        }
    }
}
