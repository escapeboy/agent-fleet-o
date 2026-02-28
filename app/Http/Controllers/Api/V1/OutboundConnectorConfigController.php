<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Outbound\Models\OutboundConnectorConfig;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OutboundConnectorConfigResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Outbound Connectors
 */
class OutboundConnectorConfigController extends Controller
{
    private const VALID_CHANNELS = 'telegram,slack,discord,teams,google_chat,whatsapp,email,webhook';

    public function index(Request $request): JsonResponse
    {
        $configs = QueryBuilder::for(OutboundConnectorConfig::class)
            ->allowedFilters(['channel', 'is_active'])
            ->allowedSorts(['created_at', 'channel'])
            ->defaultSort('-created_at')
            ->cursorPaginate($request->input('per_page', 15));

        return OutboundConnectorConfigResource::collection($configs)->response();
    }

    public function show(OutboundConnectorConfig $outboundConnectorConfig): JsonResponse
    {
        return (new OutboundConnectorConfigResource($outboundConnectorConfig))->response();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => 'required|string|in:'.self::VALID_CHANNELS,
            'credentials' => 'required|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $config = OutboundConnectorConfig::withoutGlobalScopes()->updateOrCreate(
            [
                'team_id' => $request->user()->current_team_id,
                'channel' => $validated['channel'],
            ],
            [
                'credentials' => $validated['credentials'],
                'is_active' => $validated['is_active'] ?? true,
            ],
        );

        return (new OutboundConnectorConfigResource($config->fresh()))
            ->response()
            ->setStatusCode($config->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, OutboundConnectorConfig $outboundConnectorConfig): JsonResponse
    {
        $validated = $request->validate([
            'credentials' => 'sometimes|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $outboundConnectorConfig->update($validated);

        return (new OutboundConnectorConfigResource($outboundConnectorConfig->fresh()))->response();
    }

    /**
     * @response 204
     */
    public function destroy(OutboundConnectorConfig $outboundConnectorConfig): JsonResponse
    {
        $outboundConnectorConfig->delete();

        return response()->json(null, 204);
    }

    /**
     * @response 200 {"status": "success", "message": "Connected as @mybotname"}
     * @response 422 {"status": "failed", "message": "Bot token is required"}
     */
    public function test(OutboundConnectorConfig $outboundConnectorConfig): JsonResponse
    {
        $creds = $outboundConnectorConfig->credentials ?? [];

        try {
            $result = match ($outboundConnectorConfig->channel) {
                'telegram' => $this->testTelegram($creds),
                'slack' => $this->testSlack($creds),
                'discord' => $this->testDiscord($creds),
                'teams' => $this->testTeams($creds),
                'google_chat' => $this->testGoogleChat($creds),
                'whatsapp' => $this->testWhatsApp($creds),
                'email' => $this->testSmtp($creds),
                'webhook' => $this->testWebhook($creds),
                default => throw new \RuntimeException('Unknown channel'),
            };

            $outboundConnectorConfig->update([
                'last_tested_at' => now(),
                'last_test_status' => 'success',
            ]);

            return response()->json(['status' => 'success', 'message' => $result]);
        } catch (\Throwable $e) {
            $outboundConnectorConfig->update([
                'last_tested_at' => now(),
                'last_test_status' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'failed', 'message' => $e->getMessage()], 422);
        }
    }

    private function testTelegram(array $creds): string
    {
        $token = $creds['bot_token'] ?? '';
        if (! $token) {
            throw new \RuntimeException('Bot token is required');
        }

        $response = Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getMe");

        if ($response->successful() && $response->json('ok')) {
            return 'Connected as @'.$response->json('result.username');
        }

        throw new \RuntimeException($response->json('description', 'Invalid bot token'));
    }

    private function testSlack(array $creds): string
    {
        $url = $creds['webhook_url'] ?? '';
        if (! $url) {
            throw new \RuntimeException('Webhook URL is required');
        }

        $response = Http::timeout(10)->post($url, ['text' => '[Test] FleetQ connectivity check']);

        if ($response->successful()) {
            return 'Message sent successfully';
        }

        throw new \RuntimeException('Slack returned '.$response->status().': '.$response->body());
    }

    private function testDiscord(array $creds): string
    {
        $url = $creds['webhook_url'] ?? '';
        if (! $url) {
            throw new \RuntimeException('Webhook URL is required');
        }

        $response = Http::timeout(10)->post($url.'?wait=true', ['content' => '[Test] FleetQ connectivity check']);

        if ($response->successful()) {
            return 'Message sent successfully';
        }

        throw new \RuntimeException('Discord returned '.$response->status().': '.substr($response->body(), 0, 200));
    }

    private function testTeams(array $creds): string
    {
        $url = $creds['webhook_url'] ?? '';
        if (! $url) {
            throw new \RuntimeException('Webhook URL is required');
        }

        $payload = [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => [[
                        'type' => 'TextBlock',
                        'text' => '[Test] FleetQ connectivity check',
                        'wrap' => true,
                    ]],
                ],
            ]],
        ];

        $response = Http::timeout(10)->post($url, $payload);

        if ($response->successful()) {
            return 'Card sent successfully';
        }

        throw new \RuntimeException('Teams returned '.$response->status());
    }

    private function testGoogleChat(array $creds): string
    {
        $url = $creds['webhook_url'] ?? '';
        if (! $url) {
            throw new \RuntimeException('Webhook URL is required');
        }

        $response = Http::timeout(10)->post($url, ['text' => '[Test] FleetQ connectivity check']);

        if ($response->successful()) {
            return 'Message sent successfully';
        }

        throw new \RuntimeException('Google Chat returned '.$response->status());
    }

    private function testWhatsApp(array $creds): string
    {
        $phoneNumberId = $creds['phone_number_id'] ?? '';
        $accessToken = $creds['access_token'] ?? '';

        if (! $phoneNumberId || ! $accessToken) {
            throw new \RuntimeException('Phone Number ID and Access Token are required');
        }

        $response = Http::timeout(10)
            ->withToken($accessToken)
            ->get("https://graph.facebook.com/v21.0/{$phoneNumberId}");

        if ($response->successful()) {
            $name = $response->json('verified_name') ?? $response->json('display_phone_number') ?? 'verified';

            return "Connected: {$name}";
        }

        throw new \RuntimeException($response->json('error.message', 'Invalid credentials'));
    }

    /**
     * Block SSRF by ensuring the SMTP host does not resolve to a private/loopback address.
     */
    private function assertSsrfSafeHost(string $host): void
    {
        $ip = gethostbyname($host);

        // gethostbyname returns the input unchanged on resolution failure
        if ($ip === $host && ! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \RuntimeException("Cannot resolve host: {$host}");
        }

        // Reject loopback
        if (in_array($ip, ['127.0.0.1', '0.0.0.0'], true) || str_starts_with($ip, '127.')) {
            throw new \RuntimeException("Connection to '{$host}' is not allowed.");
        }

        // Reject private, link-local, and reserved ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new \RuntimeException("Connection to '{$host}' is not allowed.");
        }
    }

    private function testSmtp(array $creds): string
    {
        $host = $creds['host'] ?? '';
        if (! $host) {
            throw new \RuntimeException('SMTP host is required');
        }

        $this->assertSsrfSafeHost($host);

        $port = (int) ($creds['port'] ?? 587);
        $encryption = $creds['encryption'] ?? 'tls';

        $errno = 0;
        $errstr = '';
        $scheme = $encryption === 'ssl' ? 'ssl://' : '';
        $fp = @fsockopen($scheme.$host, $port, $errno, $errstr, 10);

        if (! $fp) {
            throw new \RuntimeException("Cannot connect to {$host}:{$port} - {$errstr}");
        }

        $banner = fgets($fp, 512);
        fclose($fp);

        if (str_starts_with(trim($banner), '220')) {
            return 'SMTP server responded: '.trim(substr($banner, 4));
        }

        throw new \RuntimeException("Unexpected SMTP response: {$banner}");
    }

    private function testWebhook(array $creds): string
    {
        $url = $creds['default_url'] ?? '';
        if (! $url) {
            throw new \RuntimeException('Webhook URL is required');
        }

        $payload = ['test' => true, 'source' => 'fleetq', 'timestamp' => now()->toIso8601String()];
        $headers = [];
        $secret = $creds['secret'] ?? '';

        if ($secret) {
            $headers['X-Webhook-Signature'] = hash_hmac('sha256', json_encode($payload), $secret);
        }

        $response = Http::timeout(10)->withHeaders($headers)->post($url, $payload);

        if ($response->successful()) {
            return 'Webhook responded with '.$response->status();
        }

        throw new \RuntimeException('Webhook returned '.$response->status().': '.substr($response->body(), 0, 200));
    }
}
