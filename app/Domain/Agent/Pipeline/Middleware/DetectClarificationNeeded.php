<?php

namespace App\Domain\Agent\Pipeline\Middleware;

use App\Domain\Agent\Pipeline\AgentExecutionContext;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Opt-in middleware that detects ambiguous task inputs before the agent begins execution.
 * When ambiguity exceeds the configured threshold, sets requiresClarification = true
 * and short-circuits the pipeline (does NOT call $next).
 *
 * Enabled per-agent via: agent.config.clarification_detection_enabled = true
 * Threshold configurable via: agent.config.clarification_threshold (default 0.75)
 */
class DetectClarificationNeeded
{
    private const CONFIG_ENABLED_KEY = 'clarification_detection_enabled';

    private const CONFIG_THRESHOLD_KEY = 'clarification_threshold';

    private const DEFAULT_THRESHOLD = 0.75;

    private const DETECT_MODEL = 'claude-haiku-4-5';

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
    ) {}

    public function handle(AgentExecutionContext $ctx, Closure $next): AgentExecutionContext
    {
        $config = $ctx->agent->config ?? [];

        // Skip if: opt-out, or a clarification answer was already provided (resume path)
        if (! ($config[self::CONFIG_ENABLED_KEY] ?? false)
            || isset($ctx->input['clarification_answer'])
        ) {
            return $next($ctx);
        }

        try {
            $team = Team::find($ctx->teamId);
            $resolved = $this->providerResolver->resolve(agent: $ctx->agent, team: $team);

            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $resolved['provider'],
                model: self::DETECT_MODEL,
                systemPrompt: "Analyze this task input for ambiguity. Return ONLY valid JSON (no markdown) with:\n- ambiguity_score: float 0.0-1.0 (0 = completely clear, 1 = completely ambiguous)\n- question: the single most important clarifying question, or empty string if score < 0.5\n- ambiguities: array of specific ambiguous aspects",
                userPrompt: json_encode($ctx->input),
                maxTokens: 256,
                teamId: $ctx->teamId,
                agentId: $ctx->agent->id,
                experimentId: $ctx->experimentId,
                purpose: 'agent.clarification_detect',
                temperature: 0.0,
            ));

            $result = json_decode($response->content, true);

            if (! is_array($result)) {
                return $next($ctx);
            }

            $score = (float) ($result['ambiguity_score'] ?? 0.0);
            $threshold = (float) ($config[self::CONFIG_THRESHOLD_KEY] ?? self::DEFAULT_THRESHOLD);
            $question = trim($result['question'] ?? '');

            if ($score >= $threshold && $question !== '') {
                $ctx->requiresClarification = true;
                $ctx->clarificationQuestion = $question;

                Log::info('DetectClarificationNeeded: clarification required', [
                    'agent_id' => $ctx->agent->id,
                    'experiment_id' => $ctx->experimentId,
                    'score' => $score,
                    'threshold' => $threshold,
                    'question' => $question,
                ]);

                // Short-circuit: do not call $next
                return $ctx;
            }
        } catch (\Throwable $e) {
            // Detection failure is non-fatal — proceed with execution
            Log::warning('DetectClarificationNeeded: detection failed, proceeding with execution', [
                'agent_id' => $ctx->agent->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $next($ctx);
    }
}
