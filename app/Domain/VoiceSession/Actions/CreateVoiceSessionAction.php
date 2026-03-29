<?php

namespace App\Domain\VoiceSession\Actions;

use App\Domain\VoiceSession\Enums\VoiceSessionStatus;
use App\Domain\VoiceSession\Models\VoiceSession;
use Illuminate\Support\Str;

/**
 * Creates a new VoiceSession and returns a LiveKit token for the initiating participant.
 */
class CreateVoiceSessionAction
{
    public function __construct(
        private readonly GenerateLiveKitTokenAction $generateToken,
    ) {}

    /**
     * @param  string  $createdBy  User ID of the session initiator
     * @param  array  $settings  Optional overrides: stt_provider, tts_provider, voice_id, max_budget_credits
     * @param  string|null  $approvalRequestId  Link to an ApprovalRequest for voice-assisted reviews
     * @return array{session: VoiceSession, token: string}
     */
    public function execute(
        string $teamId,
        string $agentId,
        string $createdBy,
        array $settings = [],
        ?string $approvalRequestId = null,
    ): array {
        $roomName = 'room-'.Str::uuid();

        $session = VoiceSession::create([
            'team_id' => $teamId,
            'agent_id' => $agentId,
            'approval_request_id' => $approvalRequestId,
            'created_by' => $createdBy,
            'room_name' => $roomName,
            'status' => VoiceSessionStatus::Pending,
            'transcript' => [],
            'settings' => $settings,
        ]);

        $participantIdentity = 'user-'.$createdBy;

        $token = $this->generateToken->execute(
            roomName: $roomName,
            participantIdentity: $participantIdentity,
            canPublish: true,
            canSubscribe: true,
        );

        return [
            'session' => $session,
            'token' => $token,
        ];
    }
}
