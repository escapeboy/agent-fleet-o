<?php

namespace App\Mcp\Tools\Outbound;

use App\Domain\Outbound\Models\OutboundConnectorConfig;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class ConnectorConfigTestTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'connector_config_test';

    protected string $description = 'Test an outbound connector config connection. Sends a real test message/request. Updates last_tested_at and last_test_status.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Config UUID'),
            'channel' => $schema->string()->description('Channel name (alternative to id)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $id = $request->get('id');
        $channel = $request->get('channel');

        if (! $id && ! $channel) {
            return $this->invalidArgumentError('Provide either id or channel');
        }

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $config = $id
            ? OutboundConnectorConfig::withoutGlobalScopes()->where('team_id', $teamId)->find($id)
            : OutboundConnectorConfig::withoutGlobalScopes()->where('team_id', $teamId)->where('channel', $channel)->first();

        if (! $config) {
            return $this->notFoundError('connector config');
        }

        $creds = $config->credentials ?? [];

        try {
            $result = match ($config->channel) {
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

            $config->update([
                'last_tested_at' => now(),
                'last_test_status' => 'success',
            ]);

            return Response::text(json_encode([
                'status' => 'success',
                'message' => $result,
                'channel' => $config->channel,
            ]));
        } catch (\Throwable $e) {
            $config->update([
                'last_tested_at' => now(),
                'last_test_status' => $e->getMessage(),
            ]);

            return Response::text(json_encode([
                'status' => 'failed',
                'message' => $e->getMessage(),
                'channel' => $config->channel,
            ]));
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

    private function testSmtp(array $creds): string
    {
        $host = $creds['host'] ?? '';
        if (! $host) {
            throw new \RuntimeException('SMTP host is required');
        }

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
