<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shared\Models\Team;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Config
 */
class ProviderConfigController extends Controller
{
    /**
     * Get the configuration for a specific LLM provider.
     */
    public function show(Request $request, string $provider): JsonResponse
    {
        $team = Team::findOrFail($request->user()->current_team_id);

        /** @var array<string, mixed> $settings */
        $settings = (array) ($team->settings['providers'][$provider] ?? []);

        return response()->json([
            'data' => [
                'provider' => $provider,
                'config' => [
                    'settings' => $settings,
                ],
            ],
        ]);
    }

    /**
     * Update the configuration for a specific LLM provider.
     */
    public function update(Request $request, string $provider): JsonResponse
    {
        $validated = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        $team = Team::findOrFail($request->user()->current_team_id);

        /** @var array<string, mixed> $current */
        $current = (array) ($team->settings ?? []);
        $current['providers'][$provider] = $validated['settings'];

        $team->update(['settings' => $current]);

        return response()->json([
            'data' => [
                'provider' => $provider,
                'config' => [
                    'settings' => $validated['settings'],
                ],
            ],
        ]);
    }
}
