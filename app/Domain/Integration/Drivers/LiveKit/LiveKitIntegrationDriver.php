<?php

namespace App\Domain\Integration\Drivers\LiveKit;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * LiveKit integration driver.
 *
 * Stores per-team LiveKit credentials (URL, API key, API secret) and optional
 * STT/TTS provider API keys. Enables voice sessions without requiring server
 * environment variables — cloud teams connect their own LiveKit account here.
 *
 * Credential split:
 *   - secret_data: api_key, api_secret, stt_api_key, tts_api_key (encrypted)
 *   - config: url, token_ttl, stt_provider, tts_provider, tts_voice_id (plain)
 *
 * @see https://docs.livekit.io/home/get-started/authentication/
 */
class LiveKitIntegrationDriver implements IntegrationDriverInterface
{
    public function key(): string
    {
        return 'livekit';
    }

    public function label(): string
    {
        return 'LiveKit';
    }

    public function description(): string
    {
        return 'Connect your LiveKit account to enable real-time voice sessions for your agents. Supports LiveKit Cloud and self-hosted servers.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'url' => [
                'type' => 'string',
                'required' => true,
                'label' => 'LiveKit URL',
                'hint' => 'Your LiveKit server WebSocket URL, e.g. wss://your-project.livekit.cloud',
            ],
            'api_key' => [
                'type' => 'password',
                'required' => true,
                'label' => 'API Key',
                'hint' => 'Found in LiveKit Cloud → Settings → Keys, or your self-hosted server config.',
            ],
            'api_secret' => [
                'type' => 'password',
                'required' => true,
                'label' => 'API Secret',
                'hint' => 'The secret that pairs with your API key. Keep this confidential.',
            ],
            'token_ttl' => [
                'type' => 'string',
                'required' => false,
                'label' => 'Token TTL (seconds)',
                'hint' => 'How long participant tokens are valid. Default: 3600 (1 hour).',
                'default' => '3600',
            ],
            'stt_provider' => [
                'type' => 'select',
                'required' => false,
                'label' => 'STT Provider',
                'hint' => 'Speech-to-text engine for the voice worker.',
                'options' => ['deepgram', 'openai_whisper'],
                'default' => 'deepgram',
            ],
            'stt_api_key' => [
                'type' => 'password',
                'required' => false,
                'label' => 'STT API Key',
                'hint' => 'Deepgram API key (deepgram.com) or OpenAI API key for Whisper.',
            ],
            'tts_provider' => [
                'type' => 'select',
                'required' => false,
                'label' => 'TTS Provider',
                'hint' => 'Text-to-speech engine for the voice worker.',
                'options' => ['openai', 'elevenlabs'],
                'default' => 'openai',
            ],
            'tts_api_key' => [
                'type' => 'password',
                'required' => false,
                'label' => 'TTS API Key',
                'hint' => 'ElevenLabs API key (elevenlabs.io) or OpenAI API key for TTS.',
            ],
            'tts_voice_id' => [
                'type' => 'string',
                'required' => false,
                'label' => 'TTS Voice ID',
                'hint' => 'OpenAI voice: alloy, echo, fable, onyx, nova, shimmer. ElevenLabs: your custom voice ID.',
                'default' => 'alloy',
            ],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $url = $credentials['url'] ?? null;
        $apiKey = $credentials['api_key'] ?? null;
        $apiSecret = $credentials['api_secret'] ?? null;

        if (! $url || ! $apiKey || ! $apiSecret) {
            return false;
        }

        // Basic URL format check — must be wss:// or ws:// or https://
        if (! preg_match('/^(wss?|https?):\/\/.+/', $url)) {
            return false;
        }

        // Try listing rooms via LiveKit admin API to verify credentials are valid
        try {
            $httpUrl = $this->toHttpUrl($url);
            $adminToken = $this->generateAdminToken($apiKey, $apiSecret);

            $response = Http::withToken($adminToken)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->timeout(8)
                ->post("{$httpUrl}/twirp/livekit.RoomService/ListRooms", (object) []);

            // 200 = success, 401/403 = wrong credentials, 404/other = server running but unexpected
            return $response->status() === 200;
        } catch (\Throwable) {
            // Network issues — treat as invalid (user should verify URL)
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $url = $integration->config['url'] ?? null;
        $apiKey = $integration->getCredentialSecret('api_key');
        $apiSecret = $integration->getCredentialSecret('api_secret');

        if (! $url || ! $apiKey || ! $apiSecret) {
            return HealthResult::fail('LiveKit URL, API key or API secret not configured.');
        }

        $start = microtime(true);

        try {
            $httpUrl = $this->toHttpUrl($url);
            $adminToken = $this->generateAdminToken($apiKey, $apiSecret);

            $response = Http::withToken($adminToken)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->timeout(10)
                ->post("{$httpUrl}/twirp/livekit.RoomService/ListRooms", (object) []);

            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $roomCount = count($response->json('rooms') ?? []);

                return HealthResult::ok($latency, "Connected — {$roomCount} active room(s)");
            }

            return HealthResult::fail("LiveKit returned HTTP {$response->status()}");
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('generate_token', 'Generate Token', 'Generate a LiveKit participant token for a room.', [
                'room_name' => ['type' => 'string', 'required' => true, 'label' => 'Room name'],
                'participant_identity' => ['type' => 'string', 'required' => true, 'label' => 'Participant identity'],
                'can_publish' => ['type' => 'boolean', 'required' => false, 'label' => 'Can publish audio/video'],
                'can_subscribe' => ['type' => 'boolean', 'required' => false, 'label' => 'Can subscribe to tracks'],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 0;
    }

    public function poll(Integration $integration): array
    {
        return [];
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        return false;
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        return [];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        if ($action === 'generate_token') {
            $apiKey = $integration->getCredentialSecret('api_key');
            $apiSecret = $integration->getCredentialSecret('api_secret');
            $ttl = (int) ($integration->config['token_ttl'] ?? 3600);

            abort_unless($apiKey && $apiSecret, 422, 'LiveKit credentials not configured.');

            return ['token' => $this->generateParticipantToken(
                apiKey: $apiKey,
                apiSecret: $apiSecret,
                roomName: $params['room_name'],
                participantIdentity: $params['participant_identity'],
                canPublish: (bool) ($params['can_publish'] ?? true),
                canSubscribe: (bool) ($params['can_subscribe'] ?? true),
                ttl: $ttl,
            )];
        }

        throw new \InvalidArgumentException("Unknown action: {$action}");
    }

    /** Convert a WebSocket URL to HTTP for the admin REST API. */
    private function toHttpUrl(string $url): string
    {
        return preg_replace('/^wss?:\/\//', 'https://', $url) ?? $url;
    }

    /** Generate an HS256 JWT with admin grants for health checks. */
    private function generateAdminToken(string $apiKey, string $apiSecret): string
    {
        return $this->buildJwt($apiKey, $apiSecret, 60, [
            'roomCreate' => true,
            'roomList' => true,
            'roomAdmin' => true,
        ]);
    }

    /** Generate an HS256 JWT for a room participant. */
    private function generateParticipantToken(
        string $apiKey,
        string $apiSecret,
        string $roomName,
        string $participantIdentity,
        bool $canPublish,
        bool $canSubscribe,
        int $ttl,
    ): string {
        return $this->buildJwt($apiKey, $apiSecret, $ttl, [
            'roomJoin' => true,
            'room' => $roomName,
            'canPublish' => $canPublish,
            'canSubscribe' => $canSubscribe,
        ], $participantIdentity);
    }

    private function buildJwt(
        string $apiKey,
        string $apiSecret,
        int $ttl,
        array $videoClaims,
        string $subject = 'admin',
    ): string {
        $now = time();

        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode(json_encode([
            'iss' => $apiKey,
            'sub' => $subject,
            'iat' => $now,
            'exp' => $now + $ttl,
            'nbf' => $now,
            'jti' => bin2hex(random_bytes(8)),
            'video' => $videoClaims,
        ]));

        $signingInput = "{$header}.{$payload}";
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $signingInput, $apiSecret, true));

        return "{$signingInput}.{$signature}";
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
