<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Orchestration\Services\OrchestrationTierRecommender;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Orchestration
 */
class OrchestrationController extends Controller
{
    /**
     * Recommend an orchestration shape (single agent / crew / workflow) for a goal.
     * Recommendation only — never executes anything. Gated by a feature flag.
     */
    public function recommendTier(Request $request, OrchestrationTierRecommender $recommender): JsonResponse
    {
        if (! config('orchestration.tier_selector.enabled', false)) {
            return response()->json(['error' => 'feature_disabled', 'message' => 'The orchestration tier selector is not enabled.'], 404);
        }

        $validated = $request->validate([
            'goal' => ['required', 'string', 'max:2000'],
            'signals' => ['sometimes', 'array'],
            'signals.needs_parallel' => ['sometimes', 'boolean'],
            'signals.stages' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'signals.subtasks' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ]);

        return response()->json([
            'data' => $recommender->recommend($validated['goal'], $validated['signals'] ?? []),
        ]);
    }
}
