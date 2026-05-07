<?php

namespace App\Mcp\Tools\VoiceSession;

use App\Domain\VoiceSession\Actions\CreateVoiceSessionAction;
use App\Domain\VoiceSession\Exceptions\VoiceSessionException;
use App\Domain\VoiceSession\Models\VoiceSession;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class VoiceSessionCreateTool extends Tool
{
    protected string $name = 'voice_session_create';

    protected string $description = 'Start a new voice session with an agent. Returns a LiveKit room token for the browser client to connect with. Requires LIVEKIT_API_KEY and LIVEKIT_API_SECRET to be configured.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('UUID of the agent to run as a voice agent'),
            'approval_request_id' => $schema->string()
                ->description('Optional: link this session to an ApprovalRequest for voice-assisted reviews'),
            'settings' => $schema->object()
                ->description('Optional session settings: stt_provider, tts_provider, voice_id, max_budget_credits'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        $userId = auth()->user()->id ?? 'system';

        try {
            $result = app(CreateVoiceSessionAction::class)->execute(
                teamId: $teamId,
                agentId: $request->get('agent_id'),
                createdBy: $userId,
                settings: (array) ($request->get('settings', [])),
                approvalRequestId: $request->get('approval_request_id'),
            );
        } catch (VoiceSessionException $e) {
            return Response::text(json_encode(['error' => $e->getMessage()]));
        }

        /** @var VoiceSession $session */
        $session = $result['session'];

        return Response::text(json_encode([
            'session_id' => $session->id,
            'room_name' => $session->room_name,
            'status' => $session->status->value,
            'token' => $result['token'],
            'livekit_url' => config('livekit.url'),
            'created_at' => $session->created_at->toIso8601String(),
        ]));
    }
}
