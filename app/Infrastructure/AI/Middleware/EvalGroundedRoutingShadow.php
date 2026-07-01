<?php

namespace App\Infrastructure\AI\Middleware;

use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Services\EvalGroundedModelRecommender;
use App\Infrastructure\AI\Services\EvalShadowCounters;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * SHADOW / advisory eval-grounded routing (Cast AI "AI Enabler" borrow).
 *
 * Pass-through: computes the cheapest model that historically cleared the
 * quality bar for this task-type, LOGS the recommendation, and counts it — then
 * forwards the request UNCHANGED. It NEVER alters provider/model, so it is safe
 * to flip on for any provider archetype (cloud Prism, claude-code-vps, bridge).
 * Sits after BudgetPressureRouting so it sees the post-budget chosen model.
 */
class EvalGroundedRoutingShadow implements AiMiddlewareInterface
{
    public function __construct(
        private readonly EvalGroundedModelRecommender $recommender,
        private readonly EvalShadowCounters $counters,
    ) {}

    public function handle(AiRequestDTO $request, Closure $next): AiResponseDTO
    {
        if (config('ai_routing.eval_grounded.enabled') && $request->teamId) {
            try {
                $recommendation = $this->recommender->recommend($request);
                if ($recommendation !== null) {
                    Log::channel(config('ai_routing.eval_grounded.log_channel', 'stack'))
                        ->info('[eval-routing-shadow]', $recommendation);
                    $this->counters->record($request->teamId, $recommendation);
                }
            } catch (\Throwable $e) {
                // Telemetry must never break the gateway — observe-only.
                Log::warning('eval-routing-shadow failed', ['error' => $e->getMessage()]);
            }
        }

        return $next($request);
    }
}
