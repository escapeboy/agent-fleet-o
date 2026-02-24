<?php

namespace App\Http\Controllers;

use App\Domain\Signal\Connectors\SlackWebhookConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handle Slack Events API webhook.
 *
 * POST /api/signals/slack
 * Headers: X-Slack-Signature, X-Slack-Request-Timestamp
 *
 * Must respond within 3 seconds. URL verification challenge is handled synchronously.
 */
class SlackWebhookController extends Controller
{
    public function __construct(
        private readonly SlackWebhookConnector $connector,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true) ?? [];
        $type = $payload['type'] ?? '';

        // Handle URL verification challenge synchronously (before signature check per Slack docs)
        if ($type === 'url_verification') {
            return response()->json(['challenge' => $payload['challenge'] ?? ''], 200);
        }

        $secret = config('services.slack.signing_secret');

        if ($secret) {
            $timestamp = $request->header('X-Slack-Request-Timestamp', '');
            $signature = $request->header('X-Slack-Signature', '');

            if (! SlackWebhookConnector::validateSignature($rawBody, $timestamp, $signature, $secret)) {
                Log::warning('SlackWebhookController: Invalid signature', [
                    'team_id' => $payload['team_id'] ?? null,
                ]);

                return response()->json(['error' => 'Invalid signature'], 403);
            }
        }

        // Ignore event callbacks we can't handle
        if ($type !== 'event_callback') {
            return response()->json(['message' => 'Acknowledged'], 200);
        }

        $signals = $this->connector->poll(['payload' => $payload]);

        return response()->json([
            'ingested' => count($signals),
            'signal_ids' => array_map(fn ($s) => $s->id, $signals),
        ], count($signals) > 0 ? 201 : 200);
    }
}
