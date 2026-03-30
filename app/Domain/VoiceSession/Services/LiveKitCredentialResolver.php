<?php

namespace App\Domain\VoiceSession\Services;

use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;

/**
 * Resolves LiveKit and STT/TTS credentials for a given team.
 *
 * Resolution hierarchy:
 *   1. Team's active 'livekit' integration record (per-team BYOK)
 *   2. Platform-level environment variables via config('livekit.*')
 *
 * This allows cloud deployments to operate without server env vars while
 * self-hosted deployments continue using their existing .env configuration.
 */
class LiveKitCredentialResolver
{
    /**
     * Resolve the full credentials array for a team.
     *
     * @return array{url: string, api_key: string, api_secret: string, token_ttl: int, stt_provider: string, stt_api_key: string|null, tts_provider: string, tts_api_key: string|null, tts_voice_id: string}
     */
    public function resolve(Team $team): array
    {
        $integration = $this->findActiveIntegration($team);

        if ($integration) {
            return [
                'url' => $integration->config['url'] ?? config('livekit.url', 'wss://your-project.livekit.cloud'),
                'api_key' => $integration->getCredentialSecret('api_key') ?? '',
                'api_secret' => $integration->getCredentialSecret('api_secret') ?? '',
                'token_ttl' => (int) ($integration->config['token_ttl'] ?? 3600),
                'stt_provider' => $integration->config['stt_provider'] ?? config('livekit.stt.provider', 'deepgram'),
                'stt_api_key' => $integration->getCredentialSecret('stt_api_key') ?? config('livekit.stt.api_key'),
                'tts_provider' => $integration->config['tts_provider'] ?? config('livekit.tts.provider', 'openai'),
                'tts_api_key' => $integration->getCredentialSecret('tts_api_key') ?? config('livekit.tts.api_key'),
                'tts_voice_id' => $integration->config['tts_voice_id'] ?? config('livekit.tts.voice_id', 'alloy'),
            ];
        }

        // Fall back to platform-level env vars
        return [
            'url' => config('livekit.url', 'wss://your-project.livekit.cloud'),
            'api_key' => config('livekit.api_key', ''),
            'api_secret' => config('livekit.api_secret', ''),
            'token_ttl' => (int) config('livekit.token_ttl', 3600),
            'stt_provider' => config('livekit.stt.provider', 'deepgram'),
            'stt_api_key' => config('livekit.stt.api_key'),
            'tts_provider' => config('livekit.tts.provider', 'openai'),
            'tts_api_key' => config('livekit.tts.api_key'),
            'tts_voice_id' => config('livekit.tts.voice_id', 'alloy'),
        ];
    }

    /**
     * Check whether LiveKit credentials are available for a team (integration OR env vars).
     */
    public function hasCredentials(Team $team): bool
    {
        if ($this->findActiveIntegration($team)) {
            return true;
        }

        return ! empty(config('livekit.api_key')) && ! empty(config('livekit.api_secret'));
    }

    private function findActiveIntegration(Team $team): ?Integration
    {
        return Integration::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('driver', 'livekit')
            ->where('status', IntegrationStatus::Active)
            ->with('credential')
            ->latest()
            ->first();
    }
}
