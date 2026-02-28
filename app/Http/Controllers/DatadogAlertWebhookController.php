<?php

namespace App\Http\Controllers;

use App\Domain\Signal\Connectors\DatadogAlertConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DatadogAlertWebhookController extends Controller
{
    public function __construct(
        private readonly DatadogAlertConnector $connector,
    ) {}

    /**
     * Handle Datadog alert webhook.
     *
     * POST /api/signals/datadog
     *
     * Datadog does not provide HMAC signatures. Authentication is done via the
     * X-Datadog-Webhook-Secret header. Configure in Datadog Webhooks integration:
     *   URL: https://your-app.com/api/signals/datadog
     *   Header: X-Datadog-Webhook-Secret: {DATADOG_WEBHOOK_SECRET}
     *
     * The legacy URL path secret form /api/signals/datadog/{secret} is still
     * accepted for backwards compatibility but logs a deprecation warning.
     */
    public function __invoke(Request $request, string $secret = ''): JsonResponse
    {
        $expectedSecret = config('services.datadog.webhook_secret');

        // Prefer header-based secret; fall back to URL path secret (deprecated)
        $provided = $request->header('X-Datadog-Webhook-Secret', $secret);

        if ($secret && ! $request->hasHeader('X-Datadog-Webhook-Secret')) {
            Log::warning('DatadogAlertWebhookController: Secret in URL path is deprecated. Move to X-Datadog-Webhook-Secret header.');
        }

        if ($expectedSecret && ! hash_equals($expectedSecret, $provided)) {
            Log::warning('DatadogAlertWebhookController: Invalid secret token');

            return response()->json(['error' => 'Invalid secret'], 403);
        }

        $payload = $request->all();

        if (empty($payload)) {
            return response()->json(['error' => 'Empty payload'], 422);
        }

        $signals = $this->connector->poll([
            'payload' => $payload,
        ]);

        return response()->json([
            'ingested' => count($signals),
            'signal_ids' => array_map(fn ($s) => $s->id, $signals),
        ], count($signals) > 0 ? 201 : 200);
    }
}
