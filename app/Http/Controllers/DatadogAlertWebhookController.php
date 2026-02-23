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
     * POST /api/signals/datadog/{secret}
     *
     * Datadog does not provide HMAC signatures. Authentication is done by
     * embedding a secret token in the URL path and comparing it to the
     * configured value. Configure in Datadog Webhooks integration:
     *   URL: https://your-app.com/api/signals/datadog/{DATADOG_WEBHOOK_SECRET}
     */
    public function __invoke(Request $request, string $secret): JsonResponse
    {
        $expectedSecret = config('services.datadog.webhook_secret');

        if ($expectedSecret && ! hash_equals($expectedSecret, $secret)) {
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
