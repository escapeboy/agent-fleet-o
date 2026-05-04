<?php

namespace App\Infrastructure\AI\Middleware;

use App\Domain\Budget\Actions\EnforceMaxCreditsPerCallAction;
use App\Domain\Budget\Actions\ReserveBudgetAction;
use App\Domain\Budget\Actions\SettleBudgetAction;
use App\Domain\Budget\Exceptions\InsufficientBudgetException;
use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use Closure;

class BudgetEnforcement implements AiMiddlewareInterface
{
    public function __construct(
        private readonly CostCalculator $costCalculator,
        private readonly ReserveBudgetAction $reserveBudget,
        private readonly SettleBudgetAction $settleBudget,
        private readonly EnforceMaxCreditsPerCallAction $enforceMaxCap,
    ) {}

    public function handle(AiRequestDTO $request, Closure $next): AiResponseDTO
    {
        if (! $request->userId) {
            return $next($request);
        }

        // Pre-call max-credits-per-call enforcement (throws before any LLM hit).
        $this->enforceMaxCap->execute(
            teamId: $request->teamId ?? '',
            provider: $request->provider,
            model: $request->model,
            maxOutputTokens: $request->maxTokens,
            agentId: $request->agentId,
        );

        $estimatedCost = $this->costCalculator->estimateCost(
            provider: $request->provider,
            model: $request->model,
            maxTokens: $request->maxTokens,
        );

        $reservation = $this->reserveBudget->execute(
            userId: $request->userId,
            teamId: $request->teamId ?? '',
            amount: $estimatedCost,
            experimentId: $request->experimentId,
            description: "AI call reservation: {$request->purpose}",
        );

        try {
            $response = $next($request);

            $this->settleBudget->execute(
                reservation: $reservation,
                actualCost: $response->usage->costCredits,
            );

            return $response;
        } catch (InsufficientBudgetException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Release reservation on failure
            $this->settleBudget->execute(
                reservation: $reservation,
                actualCost: 0,
            );

            throw $e;
        }
    }
}
