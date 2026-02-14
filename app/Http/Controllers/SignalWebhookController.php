<?php

namespace App\Http\Controllers;

use App\Domain\Signal\Connectors\WebhookConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SignalWebhookController extends Controller
{
    public function __construct(
        private readonly WebhookConnector $webhookConnector,
    ) {}

    /**
     * Receive a webhook POST and ingest it as a signal.
     *
     * POST /api/signals/webhook
     * Headers: X-Webhook-Signature (optional HMAC-SHA256)
     */
    public function __invoke(Request $request): JsonResponse
    {
        // Validate signature if secret is configured
        $secret = config('services.signal_webhook.secret');
        if ($secret) {
            $signature = $request->header('X-Webhook-Signature', '');
            if (! WebhookConnector::validateSignature($request->getContent(), $signature, $secret)) {
                Log::warning('SignalWebhookController: Invalid signature');

                return response()->json(['error' => 'Invalid signature'], 403);
            }
        }

        $payload = $request->all();

        if (empty($payload)) {
            return response()->json(['error' => 'Empty payload'], 422);
        }

        $signals = $this->webhookConnector->poll([
            'payload' => $payload,
            'source' => $request->header('X-Webhook-Source', $request->ip()),
            'experiment_id' => $request->input('experiment_id'),
            'tags' => $request->input('tags', ['webhook']),
        ]);

        return response()->json([
            'ingested' => count($signals),
            'signal_ids' => array_map(fn ($s) => $s->id, $signals),
        ], count($signals) > 0 ? 201 : 200);
    }
}
