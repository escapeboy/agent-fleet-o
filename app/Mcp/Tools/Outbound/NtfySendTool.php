<?php

namespace App\Mcp\Tools\Outbound;

use App\Domain\Shared\Services\SsrfGuard;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * MCP tool for sending ad-hoc ntfy push notifications.
 *
 * Sends a message directly to any ntfy topic without requiring a saved
 * OutboundConnectorConfig. Useful for one-off notifications or testing.
 */
#[IsDestructive]
#[AssistantTool('write')]
class NtfySendTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'ntfy_send';

    protected string $description = 'Send a push notification via ntfy. Posts a message to a ntfy topic (https://ntfy.sh or a self-hosted server). Supports titles, priorities (min/low/default/high/max), emoji tags, and bearer-token auth for private topics.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'base_url' => $schema->string()
                ->description('Ntfy server base URL, e.g. https://ntfy.sh or https://ntfy.example.com')
                ->required(),
            'topic' => $schema->string()
                ->description('Topic name to publish to, e.g. fleetq-alerts')
                ->required(),
            'message' => $schema->string()
                ->description('Notification body text')
                ->required(),
            'title' => $schema->string()
                ->description('Notification title (optional)'),
            'priority' => $schema->string()
                ->description('Message priority: min, low, default, high, max')
                ->default('default'),
            'tags' => $schema->string()
                ->description('Comma-separated emoji shortcode tags, e.g. rotating_light,warning'),
            'token' => $schema->string()
                ->description('Bearer token for private topics (optional)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $baseUrl = rtrim((string) $request->get('base_url'), '/');
        $topic = (string) $request->get('topic');
        $message = (string) $request->get('message');
        $title = $request->get('title');
        $priority = $request->get('priority', 'default');
        $tags = $request->get('tags');
        $token = $request->get('token');

        if (! $baseUrl || ! $topic || ! $message) {
            return $this->invalidArgumentError('base_url, topic and message are required.');
        }

        $url = $baseUrl.'/'.$topic;

        // Block SSRF — validate that the assembled URL is publicly routable.
        try {
            app(SsrfGuard::class)->assertPublicUrl($url);
        } catch (\Throwable $e) {
            throw $e;
        }

        $headers = ['Priority' => $priority];

        if ($title) {
            $headers['Title'] = (string) $title;
        }

        if ($tags) {
            $headers['Tags'] = (string) $tags;
        }

        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders($headers)
                ->withBody($message, 'text/plain')
                ->post($url);

            if ($response->successful()) {
                return Response::text(json_encode([
                    'ok' => true,
                    'id' => $response->json('id'),
                    'topic' => $response->json('topic'),
                    'url' => $url,
                ]));
            }

            throw new \RuntimeException('Ntfy returned '.$response->status().': '.substr($response->body(), 0, 200));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
