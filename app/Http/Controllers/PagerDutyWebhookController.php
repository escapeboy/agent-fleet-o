<?php

namespace App\Http\Controllers;

use App\Domain\Signal\Connectors\PagerDutyConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PagerDutyWebhookController extends Controller
{
    public function __construct(
        private readonly PagerDutyConnector $connector,
    ) {}

    /**
     * Handle PagerDuty v3 webhook.
     *
     * POST /api/signals/pagerduty
     * Headers: X-PagerDuty-Signature (format: v1=<hex>,v1=<hex>), X-Webhook-ID
     */
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('services.pagerduty.webhook_secret');

        if ($secret) {
            $signatureHeader = $request->header('X-PagerDuty-Signature', '');

            if (! PagerDutyConnector::validateSignature($request->getContent(), $signatureHeader, $secret)) {
                Log::warning('PagerDutyWebhookController: Invalid signature', [
                    'webhook_id' => $request->header('X-Webhook-ID'),
                ]);

                return response()->json(['error' => 'Invalid signature'], 401);
            }
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
