<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\AgentChatProtocol\Actions\DisableExternalAgentAction;
use App\Domain\AgentChatProtocol\Actions\DispatchChatMessageAction;
use App\Domain\AgentChatProtocol\Actions\DispatchStructuredRequestAction;
use App\Domain\AgentChatProtocol\Actions\RefreshExternalAgentManifestAction;
use App\Domain\AgentChatProtocol\Actions\RegisterExternalAgentAction;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags External Agents
 */
class ExternalAgentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $teamId = (string) $request->user()->current_team_id;
        $agents = ExternalAgent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderBy('created_at', 'desc')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return response()->json([
            'data' => $agents->items(),
            'next_cursor' => $agents->nextCursor()?->encode(),
        ]);
    }

    public function show(Request $request, ExternalAgent $externalAgent): JsonResponse
    {
        $this->authorizeTeam($request, $externalAgent);

        return response()->json($externalAgent);
    }

    public function store(Request $request, RegisterExternalAgentAction $action): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'endpoint_url' => ['required', 'url', 'max:2048'],
            'manifest_url' => ['nullable', 'url', 'max:2048'],
            'credential_id' => ['nullable', 'uuid'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $teamId = (string) $request->user()->current_team_id;

        $agent = $action->execute(
            teamId: $teamId,
            name: $validated['name'],
            endpointUrl: $validated['endpoint_url'],
            manifestUrl: $validated['manifest_url'] ?? null,
            credentialId: $validated['credential_id'] ?? null,
            description: $validated['description'] ?? null,
        );

        return response()->json($agent, 201);
    }

    public function update(Request $request, ExternalAgent $externalAgent): JsonResponse
    {
        $this->authorizeTeam($request, $externalAgent);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'endpoint_url' => ['sometimes', 'url', 'max:2048'],
            'manifest_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'credential_id' => ['sometimes', 'nullable', 'uuid'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'in:active,paused,disabled'],
        ]);

        $externalAgent->update($validated);

        return response()->json($externalAgent->refresh());
    }

    public function destroy(Request $request, ExternalAgent $externalAgent, DisableExternalAgentAction $action): JsonResponse
    {
        $this->authorizeTeam($request, $externalAgent);

        $action->execute($externalAgent, softDelete: true);

        return response()->json(['status' => 'disabled'], 200);
    }

    public function refresh(Request $request, ExternalAgent $externalAgent, RefreshExternalAgentManifestAction $action): JsonResponse
    {
        $this->authorizeTeam($request, $externalAgent);
        $refreshed = $action->execute($externalAgent);

        return response()->json($refreshed);
    }

    public function ping(Request $request, ExternalAgent $externalAgent, DispatchChatMessageAction $action): JsonResponse
    {
        $this->authorizeTeam($request, $externalAgent);

        try {
            $result = $action->execute(
                externalAgent: $externalAgent,
                content: 'ping',
                from: 'fleetq:team:'.$externalAgent->team_id.':ping',
            );

            return response()->json(['status' => 'ok', 'result' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'failed', 'error' => $e->getMessage()], 502);
        }
    }

    public function sendChat(Request $request, ExternalAgent $externalAgent, DispatchChatMessageAction $action): JsonResponse
    {
        $this->authorizeTeam($request, $externalAgent);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:'.(int) config('agent_chat.inbound.max_body_bytes', 512_000)],
            'session_token' => ['nullable', 'string', 'max:128'],
            'from' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        $result = $action->execute(
            externalAgent: $externalAgent,
            content: $validated['content'],
            sessionToken: $validated['session_token'] ?? null,
            from: $validated['from'] ?? null,
            metadata: (array) ($validated['metadata'] ?? []),
        );

        return response()->json($result);
    }

    public function sendStructured(Request $request, ExternalAgent $externalAgent, DispatchStructuredRequestAction $action): JsonResponse
    {
        $this->authorizeTeam($request, $externalAgent);

        $validated = $request->validate([
            'prompt' => ['required', 'string'],
            'schema' => ['required', 'array'],
            'session_token' => ['nullable', 'string', 'max:128'],
            'from' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $action->execute(
            externalAgent: $externalAgent,
            prompt: $validated['prompt'],
            schema: $validated['schema'],
            sessionToken: $validated['session_token'] ?? null,
            from: $validated['from'] ?? null,
        );

        return response()->json($result);
    }

    private function authorizeTeam(Request $request, ExternalAgent $externalAgent): void
    {
        if ((string) $externalAgent->team_id !== (string) $request->user()->current_team_id) {
            abort(404);
        }
    }
}
