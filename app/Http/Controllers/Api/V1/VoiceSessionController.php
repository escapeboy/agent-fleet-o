<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shared\Services\PlanEnforcer;
use App\Domain\VoiceSession\Actions\AppendTranscriptAction;
use App\Domain\VoiceSession\Actions\CreateVoiceSessionAction;
use App\Domain\VoiceSession\Actions\EndVoiceSessionAction;
use App\Domain\VoiceSession\Actions\GenerateLiveKitTokenAction;
use App\Domain\VoiceSession\Enums\VoiceSessionStatus;
use App\Domain\VoiceSession\Exceptions\VoiceSessionException;
use App\Domain\VoiceSession\Models\VoiceSession;
use App\Domain\VoiceSession\Services\LiveKitCredentialResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('agent_id'),
            )
            ->allowedSorts('created_at', 'started_at', 'ended_at')
            ->defaultSort('-created_at')
            ->with(['agent:id,name'])
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return response()->json($sessions);
    }

    /**
     * Create a new voice session and return the LiveKit room token.
     */
    public function store(Request $request, CreateVoiceSessionAction $action, LiveKitCredentialResolver $resolver): JsonResponse
    {
        $teamId = $request->user()?->current_team_id;
        $request->validate([
            'agent_id' => ['required', 'uuid',
                Rule::exists('agents', 'id')->where('team_id', $teamId)],
            'approval_request_id' => ['sometimes', 'nullable', 'uuid',
                Rule::exists('approval_requests', 'id')->where('team_id', $teamId)],
            'settings' => ['sometimes', 'array'],
        ]);

        if (app()->bound(PlanEnforcer::class)) {
            $enforcer = app(PlanEnforcer::class);
            if (! $enforcer->hasFeature($request->user()->currentTeam, 'voice_agent')) {
                return response()->json([
                    'message' => 'Voice Agent is available on the Enterprise plan.',
                    'upgrade_required' => true,
                ], 403);
            }
        }

        if (! $resolver->hasCredentials($request->user()->currentTeam)) {
            return response()->json([
                'message' => 'LiveKit is not configured for your team. Connect a LiveKit integration in the Integrations page.',
            ], 422);
        }

        try {
            $result = $action->execute(
                teamId: $request->user()->current_team_id,
                agentId: $request->input('agent_id'),
                createdBy: $request->user()->id,
                settings: $request->input('settings', []),
                approvalRequestId: $request->input('approval_request_id'),
            );
        } catch (VoiceSessionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'session' => $result['session'],
            'token' => $result['token'],
            'livekit_url' => $result['livekit_url'],
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
    public function token(Request $request, VoiceSession $voiceSession, GenerateLiveKitTokenAction $action, LiveKitCredentialResolver $resolver): JsonResponse
    {
        if (! $voiceSession->isOpen()) {
            return response()->json(['message' => 'Session is not open.'], 422);
        }

        $identity = 'user-'.$request->user()->id;
        $credentials = $resolver->resolve($request->user()->currentTeam);

        try {
            $token = $action->execute(
                roomName: $voiceSession->room_name,
                participantIdentity: $identity,
                canPublish: true,
                canSubscribe: true,
                credentials: $credentials,
            );
        } catch (VoiceSessionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'token' => $token,
            'livekit_url' => $credentials['url'],
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

        // Verify the worker token was issued for this specific session.
        $tokenName = $request->user()->currentAccessToken()->name ?? '';
        if (! str_ends_with($tokenName, '-'.$voiceSession->id)) {
            return response()->json(['message' => 'Token is not authorized for this session.'], 403);
        }

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
