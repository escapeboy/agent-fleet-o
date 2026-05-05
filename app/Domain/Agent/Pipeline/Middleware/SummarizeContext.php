<?php

namespace App\Domain\Agent\Pipeline\Middleware;

use App\Domain\Agent\Enums\ExecutionTier;
use App\Domain\Agent\Pipeline\AgentExecutionContext;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Compresses oversized input via a cheap LLM call before the main agent execution.
 * Only fires when the JSON-encoded input exceeds 12 000 chars (~3 000 tokens).
 * Skipped entirely when the input already contains a clarification answer.
 */
class SummarizeContext
{
    private const CONTEXT_CHAR_THRESHOLD = 12_000;

    private const SUMMARIZE_MODEL = 'claude-haiku-4-5';

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
    ) {}

    public function handle(AgentExecutionContext $ctx, Closure $next): AgentExecutionContext
    {
        // Flash tier skips summarization — not worth the extra LLM call for fast/cheap runs
        if (ExecutionTier::fromConfig($ctx->agent->config ?? []) === ExecutionTier::Flash) {
            return $next($ctx);
        }

        $encoded = json_encode($ctx->input);

        if ($encoded === false || strlen($encoded) <= self::CONTEXT_CHAR_THRESHOLD) {
            return $next($ctx);
        }

        try {
            $team = Team::find($ctx->teamId);
            $resolved = $this->providerResolver->resolve(agent: $ctx->agent, team: $team);

            // Use fast/cheap haiku model — this is metadata work, not the main task
            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $resolved['provider'],
                model: self::SUMMARIZE_MODEL,
                systemPrompt: 'You are a context summarizer. Compress the following JSON input into the key facts needed to complete the task. Be concise and preserve all actionable information.',
                userPrompt: $encoded,
                maxTokens: 512,
                userId: $ctx->userId,
                teamId: $ctx->teamId,
                agentId: $ctx->agent->id,
                experimentId: $ctx->experimentId,
                purpose: 'agent.context_summarize',
                temperature: 0.1,
            ));

            $ctx->input = array_merge($ctx->input, [
                '_original_context_summary' => $response->content,
                '_context_summarized' => true,
            ]);
            $ctx->contextSummarized = true;
        } catch (\Throwable $e) {
            // Summarization failure is non-fatal — proceed with original input
            Log::warning('SummarizeContext: summarization failed, using original input', [
                'agent_id' => $ctx->agent->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $next($ctx);
    }
}
