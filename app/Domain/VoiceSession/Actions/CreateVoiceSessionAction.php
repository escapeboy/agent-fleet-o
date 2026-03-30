<?php

namespace App\Domain\VoiceSession\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\VoiceSession\Enums\VoiceSessionStatus;
use App\Domain\VoiceSession\Exceptions\VoiceSessionException;
use App\Domain\VoiceSession\Models\VoiceSession;
use App\Domain\VoiceSession\Services\LiveKitCredentialResolver;
use App\Infrastructure\Auth\SanctumTokenIssuer;
use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Creates a new VoiceSession and returns a LiveKit token for the initiating participant.
 *
 * Credential resolution: per-team LiveKit integration → platform env vars.
 * After creating the session, dispatches a Redis job so the voice worker can
 * join the room with the correct per-team STT/TTS credentials.
 */
class CreateVoiceSessionAction
{
    public function __construct(
        private readonly GenerateLiveKitTokenAction $generateToken,
        private readonly LiveKitCredentialResolver $credentialResolver,
    ) {}

    /**
     * @param  array  $settings  Optional overrides: stt_provider, tts_provider, voice_id, max_budget_credits
     * @param  string|null  $approvalRequestId  Link to an ApprovalRequest for voice-assisted reviews
     * @return array{session: VoiceSession, token: string, livekit_url: string}
     *
     * @throws VoiceSessionException
     */
    public function execute(
        string $teamId,
        string $agentId,
        string $createdBy,
        array $settings = [],
        ?string $approvalRequestId = null,
    ): array {
        /** @var Team $team */
        $team = Team::findOrFail($teamId);

        $credentials = $this->credentialResolver->resolve($team);

        if (empty($credentials['api_key']) || empty($credentials['api_secret'])) {
            throw VoiceSessionException::missingConfiguration();
        }

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
            credentials: $credentials,
        );

        $this->dispatchWorkerJob($session, $agentId, $credentials, $createdBy);

        return [
            'session' => $session,
            'token' => $token,
            'livekit_url' => $credentials['url'],
        ];
    }

    /**
     * Push a job to the voice_worker_dispatch Redis list so the Python voice worker
     * can join the room with the correct per-team credentials.
     *
     * The worker runs BLPOP voice_worker_dispatch 0 in a loop. Each job payload
     * contains everything the worker needs — no env vars required on its side.
     *
     * If the Redis push fails (e.g., no worker configured), we log a warning
     * and continue — the session is still usable when the worker is managed externally.
     */
    private function dispatchWorkerJob(
        VoiceSession $session,
        string $agentId,
        array $credentials,
        string $createdBy,
    ): void {
        if (! config('livekit.worker_dispatch_enabled', false)) {
            return;
        }

        try {
            $agent = Agent::find($agentId);
            $user = User::find($createdBy);

            if (! $user) {
                logger()->warning('Voice worker dispatch: user not found.', ['user_id' => $createdBy]);

                return;
            }

            // Issue a short-lived Sanctum token for the worker to POST transcripts.
            // The User model uses Passport's HasApiTokens, so we use SanctumTokenIssuer
            // instead of the standard $user->createToken() which creates Passport tokens.
            $workerToken = SanctumTokenIssuer::create(
                $user,
                'voice-worker-'.$session->id,
                ['voice-session:transcript'],
                now()->addHours(4),
            )->plainTextToken;

            $payload = json_encode([
                'session_id' => $session->id,
                'room_name' => $session->room_name,
                'livekit_url' => $credentials['url'],
                'livekit_api_key' => $credentials['api_key'],
                'livekit_api_secret' => $credentials['api_secret'],
                'stt_provider' => $credentials['stt_provider'],
                'stt_api_key' => $credentials['stt_api_key'],
                'tts_provider' => $credentials['tts_provider'],
                'tts_api_key' => $credentials['tts_api_key'],
                'tts_voice_id' => $credentials['tts_voice_id'],
                'fleetq_api_url' => config('app.url'),
                'fleetq_api_token' => $workerToken,
                'agent_name' => $agent?->name ?? 'Assistant',
                'agent_role' => $agent?->role ?? '',
                'agent_goal' => $agent?->goal ?? '',
                'agent_backstory' => $agent?->backstory ?? '',
            ]);

            Redis::connection('default')->lpush('voice_worker_dispatch', $payload);
        } catch (\Throwable $e) {
            logger()->warning('Voice worker dispatch failed — session created but worker not notified.', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
