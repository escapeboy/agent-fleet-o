<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authenticated bootstrap endpoint for MCP-compatible clients.
 *
 * Returns team-scoped configuration a client needs to self-configure:
 * provider list (with BYOK visibility), MCP endpoint, LLM defaults, role,
 * and capability flags. Pairs with the public /.well-known/fleetq document
 * which points new clients at this URL after token provisioning.
 */
class BootstrapController extends Controller
{
    public function __invoke(Request $request, ProviderResolver $providerResolver): JsonResponse
    {
        $user = $request->user();
        $team = $user?->currentTeam;

        if (! $user || ! $team) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Bootstrap requires an authenticated user with an active team.',
                ],
            ], 401);
        }

        $baseUrl = rtrim(config('app.url'), '/');
        $providers = $providerResolver->availableProviders($team);

        // Compact provider payload — clients only need name + model list.
        $providerPayload = [];
        foreach ($providers as $key => $data) {
            $providerPayload[$key] = [
                'name' => $data['name'],
                'models' => array_values(array_map(
                    fn ($m) => is_array($m) ? ($m['id'] ?? $m['name'] ?? null) : $m,
                    $data['models'],
                )),
            ];
        }

        $settings = $team->settings ?? [];
        $llmDefaults = $settings['llm_defaults'] ?? [];
        $assistantLlm = $settings['assistant_llm'] ?? [];

        $teamPivot = $user->teams()->where('teams.id', $team->id)->first();
        $role = $teamPivot?->pivot?->getAttribute('role');

        return response()->json([
            'version' => '1.0',
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
            ],
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'role' => $role,
            ],
            'endpoints' => [
                'mcp' => $baseUrl.'/mcp',
                'api' => $baseUrl.'/api/v1',
            ],
            'providers' => $providerPayload,
            'defaults' => [
                'provider' => $llmDefaults['provider'] ?? null,
                'model' => $llmDefaults['model'] ?? null,
                'assistant_provider' => $assistantLlm['provider'] ?? null,
                'assistant_model' => $assistantLlm['model'] ?? null,
            ],
            'capabilities' => [
                'mcp' => true,
                'byok' => ! empty($providerPayload),
                'codemode' => true,
            ],
        ]);
    }
}
