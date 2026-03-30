<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\VoiceSession\Actions\AppendTranscriptAction;
use App\Domain\VoiceSession\Actions\CreateVoiceSessionAction;
use App\Domain\VoiceSession\Actions\EndVoiceSessionAction;
use App\Domain\VoiceSession\Actions\GenerateLiveKitTokenAction;
use App\Domain\VoiceSession\Enums\VoiceSessionStatus;
use App\Domain\VoiceSession\Exceptions\VoiceSessionException;
use App\Domain\VoiceSession\Models\VoiceSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Voice Sessions
 */
class VoiceSessionController extends Controller
{
    /**
     * List voice sessions for the authenticated team.
     */
    public function index(Request $request): JsonResponse
    {
        $sessions = QueryBuilder::for(VoiceSession::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('agent_id'),
            ])
            ->allowedSorts(['created_at', 'started_at', 'ended_at'])
            ->defaultSort('-created_at')
            ->with(['agent:id,name'])
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return response()->json($sessions);
    }

    /**
     * Create a new voice session and return the LiveKit room token.
     */
    public function store(Request $request, CreateVoiceSessionAction $action): JsonResponse
    {
        $request->validate([
            'agent_id' => ['required', 'uuid', 'exists:agents,id'],
            'approval_request_id' => ['sometimes', 'nullable', 'uuid', 'exists:approval_requests,id'],
            'settings' => ['sometimes', 'array'],
        ]);

        $result = $action->execute(
            teamId: $request->user()->current_team_id,
            agentId: $request->input('agent_id'),
            createdBy: $request->user()->id,
            settings: $request->input('settings', []),
            approvalRequestId: $request->input('approval_request_id'),
        );

        return response()->json([
            'session' => $result['session'],
            'token' => $result['token'],
            'livekit_url' => config('livekit.url'),
        ], 201);
    }

    /**
     * Get a single voice session.
     */
    public function show(VoiceSession $voiceSession): JsonResponse
    {
        return response()->json($voiceSession->load('agent:id,name'));
    }

    /**
     * Generate a fresh LiveKit token for an existing session.
     *
     * Useful for re-joining after a network interruption or when the original token expires.
     */
    public function token(Request $request, VoiceSession $voiceSession, GenerateLiveKitTokenAction $action): JsonResponse
    {
        $request->validate([
            'participant_identity' => ['sometimes', 'string', 'max:255'],
        ]);

        if (! $voiceSession->isOpen()) {
            return response()->json(['message' => 'Session is not open.'], 422);
        }

        $identity = $request->input('participant_identity', 'user-'.$request->user()->id);

        try {
            $token = $action->execute(
                roomName: $voiceSession->room_name,
                participantIdentity: $identity,
                canPublish: true,
                canSubscribe: true,
            );
        } catch (VoiceSessionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'token' => $token,
            'livekit_url' => config('livekit.url'),
            'room_name' => $voiceSession->room_name,
        ]);
    }

    /**
     * End an active voice session.
     */
    public function destroy(VoiceSession $voiceSession, EndVoiceSessionAction $action): JsonResponse
    {
        try {
            $action->execute($voiceSession);
        } catch (VoiceSessionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Voice session ended.']);
    }

    /**
     * Append a transcript turn. Called by the Python voice worker via bearer token.
     */
    public function appendTranscript(Request $request, VoiceSession $voiceSession, AppendTranscriptAction $action): JsonResponse
    {
        $request->validate([
            'role' => ['required', 'string', 'in:user,agent,system'],
            'content' => ['required', 'string', 'max:10000'],
        ]);

        $session = $action->execute(
            session: $voiceSession,
            role: $request->input('role'),
            content: $request->input('content'),
        );

        // Mark session as active when the first transcript turn arrives
        if ($session->status === VoiceSessionStatus::Pending) {
            $session->update([
                'status' => VoiceSessionStatus::Active,
                'started_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Transcript updated.', 'turn_count' => count($session->transcript)]);
    }
}
