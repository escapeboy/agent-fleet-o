<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\AgentChatProtocol\Models\AgentChatMessage;
use App\Domain\AgentChatProtocol\Models\AgentChatSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Agent Chat Protocol
 */
class AgentChatSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $teamId = (string) $request->user()->current_team_id;
        $sessions = AgentChatSession::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderByDesc('last_activity_at')
            ->cursorPaginate(min((int) $request->input('per_page', 20), 100));

        return response()->json([
            'data' => $sessions->items(),
            'next_cursor' => $sessions->nextCursor()?->encode(),
        ]);
    }

    public function show(Request $request, AgentChatSession $session): JsonResponse
    {
        $this->authorizeTeam($request, (string) $session->team_id);

        return response()->json($session->load(['agent', 'externalAgent']));
    }

    public function messages(Request $request, AgentChatSession $session): JsonResponse
    {
        $this->authorizeTeam($request, (string) $session->team_id);

        $messages = AgentChatMessage::withoutGlobalScopes()
            ->where('team_id', $session->team_id)
            ->where('session_id', $session->id)
            ->orderBy('created_at')
            ->cursorPaginate(min((int) $request->input('per_page', 100), 500));

        return response()->json([
            'data' => $messages->items(),
            'next_cursor' => $messages->nextCursor()?->encode(),
        ]);
    }

    private function authorizeTeam(Request $request, string $teamId): void
    {
        if ($teamId !== (string) $request->user()->current_team_id) {
            abort(404);
        }
    }
}
